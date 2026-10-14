<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** @mixin \App\Models\Product */
class ProductDetailResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['sku' => $this->sku, 'description' => $this->description];
    }
}
