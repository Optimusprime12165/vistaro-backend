<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and update (PATCH) for categories. */
class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($this->route('category')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image' => ['sometimes', 'nullable', 'file', 'image', 'mimes:' . implode(',', config('vistaro.images.mimes')), 'max:' . config('vistaro.images.max_kb')],
            'image_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
