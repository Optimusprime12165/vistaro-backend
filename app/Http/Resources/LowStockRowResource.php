<?php

namespace App\Http\Resources;

use App\Support\StockDisplay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One size of one product that is below the low-stock threshold. @mixin \App\Models\ProductSize|\App\Models\OfflineStock */
class LowStockRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'product_slug' => $this->product->slug,
            'sku' => $this->product->sku,
            'size' => $this->size->value,
            'quantity' => $this->quantity,
            'status' => StockDisplay::adminStatus($this->quantity),
        ];
    }
}
