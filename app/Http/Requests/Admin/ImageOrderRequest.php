<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImageOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['order' => ['required', 'array', 'min:1', 'max:' . config('vistaro.images.max_per_product')], 'order.*' => ['integer', 'distinct']];
    }
}
