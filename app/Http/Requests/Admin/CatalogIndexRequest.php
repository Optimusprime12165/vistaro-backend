<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

/** Admin list of categories / sub-categories. */
class CatalogIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'name', 'sort_order', 'created_at'];
    }

    protected function filterRules(): array
    {
        return ['is_active' => ['sometimes', 'boolean'], 'category_id' => ['sometimes', 'integer', 'min:1']];
    }
}
