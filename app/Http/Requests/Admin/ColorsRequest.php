<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Replaces the product's colour list (an empty list removes all colours). */
class ColorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'colors' => ['present', 'array', 'max:12'],
            'colors.*.name' => ['required', 'string', 'min:1', 'max:40', 'distinct:ignore_case'],
            'colors.*.hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
