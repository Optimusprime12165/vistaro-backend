<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable snapshot of the delivery address at purchase time. */
class OrderAddress extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'name', 'phone', 'line1', 'landmark', 'city', 'state', 'pincode'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order addresses are immutable snapshots.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
