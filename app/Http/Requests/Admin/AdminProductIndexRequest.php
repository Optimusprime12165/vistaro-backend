<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class AdminProductIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'name', 'price', 'created_at', 'stock'];
    }

    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'sub_category_id' => ['sometimes', 'integer', 'min:1'],
            'is_available' => ['sometimes', 'boolean'],
            'stock_status' => ['sometimes', 'in:in,low,out'],
        ];
    }
}
