<?php

namespace App\Http\Resources;

use App\Support\ImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Category */
class CategoryResource extends JsonResource
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
            'sub_categories_count' => $this->whenCounted('subCategories'),
            'sub_categories' => SubCategoryResource::collection($this->whenLoaded('subCategories')),
        ] + ($request->routeIs('v1.admin.*') ? ['is_active' => $this->is_active] : []);
    }
}
