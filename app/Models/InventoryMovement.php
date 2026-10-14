<?php

namespace App\Models;

use App\Enums\InventoryLocation;
use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only stock ledger. */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_size_id', 'product_id', 'size', 'location', 'type', 'quantity_change', 'reserved_change',
        'quantity_after', 'reference_type', 'reference_id', 'actor_type', 'actor_id', 'note',
    ];

    protected function casts(): array
    {
        return ['location' => InventoryLocation::class, 'type' => InventoryMovementType::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Inventory movements are append-only.'));
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class);
    }
}
