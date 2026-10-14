<?php

namespace App\Http\Requests\Admin;

use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => ['required', 'in:online,offline'],
            'size' => ['required', Rule::in(Size::values())],
            'delta' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
