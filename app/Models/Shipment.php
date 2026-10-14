<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'order_id', 'type', 'provider', 'is_mock', 'awb', 'carrier', 'status', 'pickup_reference',
        'pickup_requested_at', 'picked_up_at', 'shipped_at', 'delivered_at', 'expected_delivery_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'is_mock' => 'boolean',
            'pickup_requested_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'expected_delivery_date' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
