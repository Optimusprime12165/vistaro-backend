<?php

namespace App\Models;

use Database\Factories\ProductSizeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * THE shared stock of one product in one size. `quantity` is what can be sold right now, whether the sale is an
 * Online order or a manual Offline (shop) adjustment: there is exactly one counter per (product, size).
 * `reserved` = units held by not-yet-fulfilled online orders (moved out of quantity).
 * `is_enabled` switches the size on/off for sale WITHOUT touching quantity.
 * Stock must only be changed through App\Services\Inventory\InventoryService, never by ad-hoc ->save().
 */
class ProductSize extends Model
{
    /** @use HasFactory<ProductSizeFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'size', 'sku', 'quantity', 'reserved', 'is_enabled'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved' => 'integer', 'is_enabled' => 'boolean'];
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('is_enabled', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
