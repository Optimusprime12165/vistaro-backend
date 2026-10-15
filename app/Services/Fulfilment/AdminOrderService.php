<?php

namespace App\Services\Fulfilment;

use App\Abstracts\AbstractCrudService;
use App\Models\Admin;
use App\Models\Order;
use App\Services\Concerns\MatchesMobileNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Admin order listing and detail. Orders are read-only here: they change only through FulfilmentService.
 * Listing uses CURSOR pagination: orders are append-heavy and newest-first, so a cursor never skips or repeats a row
 * when new orders arrive between page loads, and needs no COUNT(*).
 */
final class AdminOrderService extends AbstractCrudService
{
    use MatchesMobileNumbers;

    protected function modelClass(): string
    {
        return Order::class;
    }

    protected function paginationMode(): string
    {
        return self::CURSOR;
    }

    protected function sortable(): array
    {
        return ['id' => 'id', 'placed_at' => 'placed_at', 'total' => 'total'];
    }

    protected function filterable(): array
    {
        return [
            'status' => 'status',
            'payment_status' => 'payment_status',
            'payment_method' => 'payment_method',
            'user_id' => 'user_id',
            'date_from' => fn (Builder $q, $v) => $q->where('placed_at', '>=', $v . ' 00:00:00'),
            'date_to' => fn (Builder $q, $v) => $q->where('placed_at', '<=', $v . ' 23:59:59'),
        ];
    }

    protected function with(): array
    {
        return ['user:id,name,mobile', 'shipment:id,order_id,awb,carrier,status,is_mock'];
    }

    protected function newQuery(): Builder
    {
        return parent::newQuery()->withCount('items');
    }

    /** Order number, customer name and phone: all prefix matches on indexed columns. */
    protected function applySearch(Builder $builder, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $builder->where(function (Builder $q) use ($term) {
            $like = $this->escapeLike($term) . '%';
            $q->where('order_number', 'like', $like)->orWhere('contact_name', 'like', $like);
            $this->orWhereMobile($q, 'contact_phone', $term);
        });
    }

    /** Full detail, with the name of every admin who acted on the order. */
    public function detail(int $id): Order
    {
        $order = Order::query()->with(['user:id,name,mobile,email', 'items', 'address', 'payments', 'shipment.events', 'statusHistories'])->findOrFail($id);

        $admins = Admin::query()->whereIn('id', $order->statusHistories->where('actor_type', 'admin')->pluck('actor_id')->unique())->pluck('name', 'id');
        foreach ($order->statusHistories as $h) {
            $h->setAttribute('actor_name', match ($h->actor_type) {
                'admin' => $admins[$h->actor_id] ?? 'Admin #' . $h->actor_id,
                'user' => $order->user?->name ?: 'Customer',
                default => 'System',
            });
        }

        return $order;
    }

    public function store(array $data): Model
    {
        throw new LogicException('Orders are created by customers through checkout only.');
    }

    public function update(int|string|Model $target, array $data): Model
    {
        throw new LogicException('Orders change only through FulfilmentService transitions.');
    }

    public function destroy(int|string|Model $target): void
    {
        throw new LogicException('Orders are never deleted.');
    }
}
