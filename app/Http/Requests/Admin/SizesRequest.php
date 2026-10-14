<?php

namespace App\Http\Requests\Admin;

use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bulk create/update of the sizes a product is sold in. Quantities, when given, are set absolutely. */
class SizesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sizes' => ['required', 'array', 'min:1', 'max:8'],
            'sizes.*.size' => ['required', Rule::in(Size::values()), 'distinct'],
            'sizes.*.quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'sizes.*.offline_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
