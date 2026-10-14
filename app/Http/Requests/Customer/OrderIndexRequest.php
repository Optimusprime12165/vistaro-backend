<?php

namespace App\Http\Requests\Customer;

use App\Enums\OrderStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/** "My orders": newest first, simple pagination (Load more), optional status filter. */
class OrderIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id'];
    }

    protected function filterRules(): array
    {
        return ['status' => ['sometimes', Rule::enum(OrderStatus::class)]];
    }
}
