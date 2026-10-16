<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of an order. List rows carry the summary; the detail view (relations loaded) adds items, address,
 * payments, shipment with tracking, the full history with who did what, and the transitions THIS admin may perform.
 *
 * @mixin \App\Models\Order
 */
class AdminOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'order_number' => $r->order_number,
            'status' => $r->status->value,
            'payment_status' => $r->payment_status->value,
            'payment_method' => $r->payment_method->value,
            'total' => $r->total,
            'currency' => $r->currency,
            'placed_at' => $r->placed_at->toIso8601String(),
            'customer' => $this->whenLoaded('user', fn () => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'mobile' => $r->user->mobile] : null),
            'contact' => ['name' => $r->contact_name, 'phone' => $r->contact_phone, 'email' => $r->contact_email],
            'items_count' => $this->whenCounted('items'),
            'shipment' => $this->whenLoaded('shipment', fn () => $r->shipment ? $this->shipment($r->shipment, $r->relationLoaded('shipment') && $r->shipment->relationLoaded('events')) : null),

            'subtotal' => $this->when($r->relationLoaded('items'), $r->subtotal),
            'mrp_total' => $this->when($r->relationLoaded('items'), $r->mrp_total),
            'discount_total' => $this->when($r->relationLoaded('items'), $r->discount_total),
            'shipping_fee' => $this->when($r->relationLoaded('items'), $r->shipping_fee),
            'items' => $this->whenLoaded('items', fn () => $r->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'product_size_id' => $i->product_size_id, 'name' => $i->product_name, 'sku' => $i->sku,
                'size' => $i->size, 'color' => $i->color_name ? ['name' => $i->color_name, 'hex' => $i->color_hex] : null, 'image' => $i->image,
                'unit_price' => $i->unit_price, 'mrp' => $i->mrp, 'quantity' => $i->quantity, 'line_total' => $i->line_total,
            ])->values()),
            'address' => $this->whenLoaded('address', fn () => $r->address ? $r->address->only(['name', 'phone', 'line1', 'landmark', 'city', 'state', 'pincode']) : null),
            'payments' => $this->whenLoaded('payments', fn () => $r->payments->map(fn ($p) => [
                'id' => $p->id, 'method' => $p->method->value, 'gateway' => $p->gateway, 'status' => $p->status->value, 'amount' => $p->amount,
                'reference' => $p->reference, 'is_mock' => $p->is_mock, 'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values()),
            'history' => $this->whenLoaded('statusHistories', fn () => $r->statusHistories->map(fn ($h) => [
                'from' => $h->from_status?->value, 'status' => $h->to_status->value, 'reason' => $h->reason,
                'actor' => ['type' => $h->actor_type, 'id' => $h->actor_id, 'name' => $h->getAttribute('actor_name')], 'at' => $h->created_at->toIso8601String(),
            ])->values()),
            'allowed_transitions' => $this->when($r->relationLoaded('statusHistories'), fn () => $this->allowed($r->status, $request)),
            'can_request_pickup' => $this->when($r->relationLoaded('statusHistories'), $r->status === OrderStatus::Packed),
        ];
    }

    /** What THIS admin may do next: pickup_requested has its own endpoint, and staff cannot cancel/receive returns/refund. */
    private function allowed(OrderStatus $from, Request $request): array
    {
        $admin = $request->user();

        return collect($from->next())
            ->reject(fn (OrderStatus $s) => $s === OrderStatus::PickupRequested)
            ->reject(fn (OrderStatus $s) => $s->requiresAdminRole() && ! $admin?->hasRole(\App\Enums\AdminRole::SuperAdmin))
            ->map(fn (OrderStatus $s) => $s->value)->values()->all();
    }

    private function shipment($s, bool $withEvents): array
    {
        return [
            'id' => $s->id, 'provider' => $s->provider, 'is_mock' => $s->is_mock, 'awb' => $s->awb, 'carrier' => $s->carrier, 'status' => $s->status->value,
            'pickup_reference' => $s->pickup_reference,
            'pickup_requested_at' => $s->pickup_requested_at?->toIso8601String(), 'shipped_at' => $s->shipped_at?->toIso8601String(), 'delivered_at' => $s->delivered_at?->toIso8601String(),
            'notice' => $s->is_mock ? 'Simulated shipment (mock courier): this is NOT a real courier booking.' : null,
            'events' => $withEvents ? $s->events->map(fn ($e) => ['status' => $e->status, 'location' => $e->location, 'description' => $e->description, 'at' => $e->occurred_at->toIso8601String()])->values() : null,
        ];
    }
}
