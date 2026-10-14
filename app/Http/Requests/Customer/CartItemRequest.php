<?php

namespace App\Http\Requests\Customer;

use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST: product_id + size (+ color_id) + quantity.  PATCH: any of quantity / size / color_id.
 * Prices, discounts and totals are NOT accepted: unknown fields are ignored by validated().
 */
class CartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = (int) config('vistaro.cart.max_line_quantity');

        if ($this->isMethod('POST')) {
            return [
                'product_id' => ['required', 'integer', 'min:1'],
                'size' => ['required', Rule::in(Size::values())],
                'color_id' => ['nullable', 'integer', 'min:1'],
                'quantity' => ['required', 'integer', 'min:1', "max:{$max}"],
            ];
        }

        return [
            'size' => ['sometimes', Rule::in(Size::values())],
            'color_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'integer', 'min:1', "max:{$max}"],
        ];
    }

    public function after(): array
    {
        return [function ($v) {
            if (! $this->isMethod('POST') && $this->only(['size', 'color_id', 'quantity']) === []) {
                $v->errors()->add('quantity', 'Provide quantity, size or color_id to change.');
            }
        }];
    }
}
