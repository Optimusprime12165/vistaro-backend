<?php

namespace App\Http\Resources;

use App\Support\ImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SubCategory */
class SubCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => ImageUrl::resolve($this->image_path),
            'sort_order' => $this->sort_order,
            'products_count' => $this->whenCounted('products'),
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug]),
        ] + ($request->routeIs('v1.admin.*') ? ['is_active' => $this->is_active, 'category_id' => $this->category_id] : []);
    }
}
