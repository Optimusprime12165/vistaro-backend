<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class FacetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category' => ['sometimes', 'string', 'max:140'], 'sub_category' => ['sometimes', 'string', 'max:140']];
    }
}
