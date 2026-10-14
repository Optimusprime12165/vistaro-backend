<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

/** Size-wise stock table for either location. */
class InventoryIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'name', 'stock'];
    }

    protected function filterRules(): array
    {
        return [
            'location' => ['sometimes', 'in:online,offline'],
            'stock_status' => ['sometimes', 'in:in,low,out'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'sub_category_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function location(): string
    {
        return $this->validated('location', 'online');
    }
}
