<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer view of an order. Everything shown comes from the immutable snapshots taken at purchase time.
 * Relations are shown only when loaded (list loads items + payments; detail adds address + history).
 *
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->relationLoaded('payments') ? $this->payments->sortByDesc('id')->first() : null;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'payment_method' => $this->payment_method->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'mrp_total' => $this->mrp_total,
            'discount_total' => $this->discount_total,
            'shipping_fee' => $this->shipping_fee,
            'total' => $this->total,
            'placed_at' => $this->placed_at->toIso8601String(),
            'can_cancel' => $this->status->canCustomerCancel(),
            'can_return' => $this->status->canCustomerReturn(),
            'payment' => $payment ? [
                'method' => $payment->method->value,
                'status' => $payment->status->value,
                'is_mock' => $payment->is_mock,
                'message' => $this->paymentMessage($payment),
            ] : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->product_name,
                'sku' => $i->sku,
                'size' => $i->size,
                'color' => $i->color_name ? ['name' => $i->color_name, 'hex' => $i->color_hex] : null,
                'image' => $i->image,
                'unit_price' => $i->unit_price,
                'mrp' => $i->mrp,
                'quantity' => $i->quantity,
                'line_total' => $i->line_total,
            ])->values()),
            'address' => $this->whenLoaded('address', fn () => $this->address ? [
                'name' => $this->address->name, 'phone' => $this->address->phone, 'line1' => $this->address->line1,
                'landmark' => $this->address->landmark, 'city' => $this->address->city, 'state' => $this->address->state, 'pincode' => $this->address->pincode,
            ] : null),
            'shipment' => $this->whenLoaded('shipment', fn () => $this->shipment ? [
                'awb' => $this->shipment->awb,
                'carrier' => $this->shipment->carrier,
                'status' => $this->shipment->status->value,
                'is_mock' => $this->shipment->is_mock,
                'shipped_at' => $this->shipment->shipped_at?->toIso8601String(),
                'delivered_at' => $this->shipment->delivered_at?->toIso8601String(),
                'tracking' => $this->shipment->events->sortByDesc('occurred_at')->take(50)->map(fn ($e) => [
                    'status' => $e->status, 'location' => $e->location, 'description' => $e->description, 'at' => $e->occurred_at->toIso8601String(),
                ])->values(),
            ] : null),
            'history' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(fn ($h) => [
                'status' => $h->to_status->value, 'from' => $h->from_status?->value,
                'reason' => $h->actor_type === 'admin' ? null : $h->reason,      // internal admin notes are never shown to the customer
                'at' => $h->created_at->toIso8601String(),
            ])->values()),
        ];
    }

    private function paymentMessage($payment): string
    {
        return match (true) {
            $payment->status->value === 'paid' => 'Payment received.',
            $payment->status->value === 'cancelled' => 'Order cancelled before any payment was collected.',
            $payment->status->value === 'refund_pending' => 'A refund is pending.',
            $payment->status->value === 'refunded' => 'Payment refunded.',
            $payment->method->value === 'cod' => 'Pay in cash on delivery.',
            $payment->is_mock => 'Development mode: no real payment was collected.',
            default => 'Awaiting payment confirmation.',
        };
    }
}
