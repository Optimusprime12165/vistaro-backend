<?php

namespace App\Models;

use App\Enums\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Physical-shop stock. Independent from online stock and from orders. */
class OfflineStock extends Model
{
    protected $fillable = ['product_id', 'size', 'quantity'];

    protected function casts(): array
    {
        return ['size' => Size::class, 'quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
