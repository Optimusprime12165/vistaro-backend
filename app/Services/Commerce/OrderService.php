<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\ListQuery;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Resources\OrderResource;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\ProductSize;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Support\ImageUrl;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer-side order lifecycle: place, list, show, cancel, request return. Shipping/fulfilment transitions are Phase 7.
 *
 * Stock policy (applied identically for COD and online): placing an order RESERVES stock (available -> reserved).
 * Cancelling before dispatch RELEASES it. Fulfilment (Phase 7) consumes the reservation.
 * Payment status is separate from order status and is never "paid" without a provider confirmation.
 */
final class OrderService
{
    private const RELATIONS_DETAIL = ['items', 'address', 'statusHistories', 'payments', 'shipment.events'];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly CheckoutService $checkout,
        private readonly InventoryService $inventory,
        private readonly PaymentGatewayInterface $gateway,
        private readonly IdempotencyService $idempotency,
    ) {
    }

    // -------------------------------------------------------------------- place

    /** @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function place(User $user, Address $address, PaymentMethod $method, string $idempotencyKey): array
    {
        $this->checkout->assertMethodAvailable($method);

        $hash = hash('sha256', json_encode(['address_id' => $address->id, 'payment_method' => $method->value]));

        return $this->idempotency->run($user, 'orders.store', $idempotencyKey, $hash, fn () => $this->create($user, $address, $method));
    }

    /** Runs inside the idempotency transaction: any exception rolls back the order, items, stock reservation, payment and cart clearing together. */
    private function create(User $user, Address $address, PaymentMethod $method): array
    {
        $cart = Cart::query()->where('user_id', $user->id)->lockForUpdate()->first();
        $items = $cart ? $cart->items()->with(CartService::RELATIONS)->orderBy('product_size_id')->orderBy('id')->get() : new EloquentCollection();

        if ($items->isEmpty()) {
            throw new ApiException('Your cart is empty.', 422, ['cart' => ['Your cart is empty.']]);
        }

        // Lock every stock row we will touch (ascending id => no deadlocks between buyers) and price against the LOCKED values.
        $locked = ProductSize::query()->whereIn('id', $items->pluck('product_size_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $item) {
            $fresh = $locked[$item->product_size_id];
            $fresh->setRelation('product', $item->productSize->product);
            $item->setRelation('productSize', $fresh);
        }

        $priced = $this->pricing->build($items);
        if (! $priced['can_checkout']) {
            $this->refuse($priced['issues']);
        }
        $totals = $priced['totals'];
        $lines = collect($priced['items'])->keyBy('id');

        $order = Order::query()->create([
            'order_number' => 'TMP' . strtoupper(Str::random(12)),               // replaced right below, once the id is known
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => $method,
            'subtotal' => $totals['subtotal'], 'mrp_total' => $totals['mrp_total'], 'discount_total' => $totals['discount_total'],
            'shipping_fee' => $totals['shipping_fee'], 'total' => $totals['total'], 'currency' => $totals['currency'],
            'contact_name' => $address->name, 'contact_email' => $user->email, 'contact_phone' => $address->phone,
            'placed_at' => now(),
        ]);
        $order->forceFill(['order_number' => 'VS' . (10000 + $order->id)])->save();   // existing convention: VS10245

        foreach ($items as $item) {                                                  // immutable snapshots of what was bought
            $ps = $item->productSize;
            $p = $ps->product;
            OrderItem::query()->create([
                'order_id' => $order->id, 'product_id' => $p->id, 'product_size_id' => $ps->id,
                'product_name' => $p->name, 'sku' => $ps->sku ?? $p->sku, 'size' => $ps->size->value,
                'color_name' => $item->color?->name, 'color_hex' => $item->color?->hex,
                'image' => $p->primaryImage ? ImageUrl::resolve($p->primaryImage->path, $p->primaryImage->disk) : null,
                'unit_price' => $p->price, 'mrp' => $p->mrp, 'quantity' => $item->quantity, 'line_total' => $lines[$item->id]['line_subtotal'],
            ]);
        }

        OrderAddress::query()->create([
            'order_id' => $order->id, 'name' => $address->name, 'phone' => $address->phone, 'line1' => $address->line1,
            'landmark' => $address->landmark, 'city' => $address->city, 'state' => $address->state, 'pincode' => $address->pincode,
        ]);

        // One reservation per stock row (several colour lines of one size are summed); conditional UPDATE refuses to oversell.
        foreach ($items->groupBy('product_size_id')->map(fn ($g) => (int) $g->sum('quantity'))->sortKeys() as $productSizeId => $quantity) {
            $this->inventory->reserve($productSizeId, $quantity, 'order', $order->id, $user);
        }

        OrderStatusHistory::query()->create([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending,
            'actor_type' => 'user', 'actor_id' => $user->id, 'reason' => 'Order placed',
        ]);

        $this->recordPayment($order, $method);
        $cart->items()->delete();                                                    // only reached when everything above succeeded

        $order->load(self::RELATIONS_DETAIL);
        $payment = $this->checkout->paymentInfo($method);

        return [
            'status' => 201,
            'order_id' => $order->id,
            'body' => [
                'message' => $method === PaymentMethod::Cod
                    ? 'Order placed. Please pay in cash on delivery.'
                    : ($payment['is_mock'] ? 'Order placed. Payment is pending: this is a development order and no money was collected.' : 'Order placed. Payment is pending.'),
                'data' => OrderResource::make($order)->resolve(request()),
            ],
        ];
    }

    /** Payment never starts as "paid": a gateway's initiation result is not a confirmation. */
    private function recordPayment(Order $order, PaymentMethod $method): void
    {
        $result = $method === PaymentMethod::Cod ? null : $this->gateway->initiate($order->order_number, (string) $order->total, $method->value);

        Payment::query()->create([
            'order_id' => $order->id, 'method' => $method, 'gateway' => $result?->gateway,
            'status' => PaymentStatus::Pending, 'amount' => $order->total, 'currency' => $order->currency,
            'reference' => $result?->reference, 'is_mock' => $result?->isMock ?? false,
        ]);
    }

    /** @param list<array<string,mixed>> $issues */
    private function refuse(array $issues): never
    {
        $errors = [];
        foreach ($issues as $i) {
            $errors["items.{$i['item_id']}"] = [$i['message']];
        }
        $stock = collect($issues)->contains(fn ($i) => in_array($i['code'], ['out_of_stock', 'insufficient_stock'], true));

        throw $stock
            ? new InsufficientStockException('Some items are no longer available in the quantity you asked for.', $errors)
            : new ApiException('Some items in your cart are no longer available.', 422, $errors);
    }

    // --------------------------------------------------------------------- read

    public function list(User $user, ListQuery $query): Paginator
    {
        return $user->orders()->with(['items', 'payments'])
            ->when($query->filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->simplePaginate($query->perPage, ['*'], 'page', $query->page);
    }

    public function show(User $user, int $id): Order
    {
        return $user->orders()->with(self::RELATIONS_DETAIL)->findOrFail($id);      // 404 for anyone else's order
    }

    // ------------------------------------------------------- cancel / return

    public function cancel(User $user, int $id, ?string $reason): Order
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $order = $user->orders()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! $order->status->canCustomerCancel()) {
                throw new InvalidStateTransitionException($order->status->value, OrderStatus::Cancelled->value);
            }

            foreach ($order->items()->whereNotNull('product_size_id')->orderBy('product_size_id')->get() as $item) {
                $this->inventory->release($item->product_size_id, $item->quantity, 'order', $order->id, $user, 'Cancelled by customer');
            }

            $paid = $order->payment_status === PaymentStatus::Paid;
            $newPayment = $paid ? PaymentStatus::RefundPending : PaymentStatus::Cancelled;     // refunds themselves are an admin/provider matter

            $this->transition($order, OrderStatus::Cancelled, $user, $reason ?: 'Cancelled by customer');
            $order->forceFill(['payment_status' => $newPayment])->save();
            $order->payments()->whereNotIn('status', [PaymentStatus::Refunded->value])->update(['status' => $newPayment->value]);
        }, 3);

        return $this->show($user, $id);
    }

    public function requestReturn(User $user, int $id, string $reason): Order
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $order = $user->orders()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! $order->status->canCustomerReturn()) {
                throw new InvalidStateTransitionException($order->status->value, OrderStatus::ReturnRequested->value);
            }

            $deliveredAt = $order->statusHistories()->where('to_status', OrderStatus::Delivered->value)->latest('id')->value('created_at');
            $days = (int) config('vistaro.orders.return_window_days');
            if ($deliveredAt && now()->greaterThan(\Illuminate\Support\Carbon::parse($deliveredAt)->addDays($days))) {
                throw new ApiException("The {$days}-day return window for this order has closed.", 422, ['order' => ["Returns are accepted within {$days} days of delivery."]]);
            }

            $this->transition($order, OrderStatus::ReturnRequested, $user, $reason);
        }, 3);

        return $this->show($user, $id);
    }

    private function transition(Order $order, OrderStatus $to, User $actor, ?string $reason): void
    {
        $from = $order->status;
        $order->forceFill(['status' => $to])->save();
        OrderStatusHistory::query()->create([
            'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
            'actor_type' => 'user', 'actor_id' => $actor->id, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    }
}
