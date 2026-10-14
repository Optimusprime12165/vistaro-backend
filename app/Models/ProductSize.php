<?php

namespace App\Models;

use App\Enums\Size;
use Database\Factories\ProductSizeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Online inventory for one product in one size. `quantity` is what can be sold right now.
 * Stock must only be changed through the inventory service (Phase 5/6), never by ad-hoc ->save().
 */
class ProductSize extends Model
{
    /** @use HasFactory<ProductSizeFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'size', 'sku', 'quantity', 'reserved'];

    protected function casts(): array
    {
        return ['size' => Size::class, 'quantity' => 'integer', 'reserved' => 'integer'];
    }

    protected static function booted(): void
    {
        // Consistency rule: every product-size has a matching (possibly zero) offline stock row.
        static::created(function (ProductSize $ps) {
            OfflineStock::query()->firstOrCreate(
                ['product_id' => $ps->product_id, 'size' => $ps->size->value],
                ['quantity' => 0],
            );
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function offlineStock(): HasOne
    {
        return $this->hasOne(OfflineStock::class, 'product_id', 'product_id')
            ->whereColumn('offline_stocks.size', 'product_sizes.size');
    }
}
