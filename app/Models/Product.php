<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id', 'sub_category_id', 'name', 'slug', 'sku', 'description', 'price', 'mrp', 'currency',
        'attributes', 'status', 'is_available', 'is_offline_available', 'is_bestseller', 'is_new_arrival',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'attributes' => 'array',
            'status' => ProductStatus::class,
            'is_available' => 'boolean',
            'is_offline_available' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_new_arrival' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // discount_percent is derived, never client-supplied; stored only so "sale" filters can use an index.
        static::saving(function (Product $p) {
            $mrp = (float) $p->mrp;
            $price = (float) $p->price;
            $p->discount_percent = $mrp > 0 && $price < $mrp ? (int) round(($mrp - $price) / $mrp * 100) : 0;
        });
    }

    /** Public ONLINE catalogue: active AND sold online (`is_available`). Offline-only products never match. */
    public function scopeStorefront(Builder $q): Builder
    {
        return $q->where('status', ProductStatus::Active->value)->where('is_available', true);
    }

    /** Admin stock tabs: 'online' = sold online, 'offline' = sold in the shop. A "both" product matches both tabs. */
    public function scopeForChannel(Builder $q, string $channel): Builder
    {
        return $q->where($channel === 'offline' ? 'is_offline_available' : 'is_available', true);
    }

    /** online | offline | both | none (derived from the two availability flags; the stock itself is NOT split by channel). */
    public function channel(): string
    {
        return match (true) {
            $this->is_available && $this->is_offline_available => 'both',
            $this->is_available => 'online',
            $this->is_offline_available => 'offline',
            default => 'none',
        };
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function colors(): HasMany
    {
        return $this->hasMany(ProductColor::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class)->orderBy('id');   // display order is applied by SizeCode::sortKey()
    }

}
