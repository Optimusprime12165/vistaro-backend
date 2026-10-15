<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class AdminOrderIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'placed_at', 'total'];
    }

    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
