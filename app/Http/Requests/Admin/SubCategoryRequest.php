<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class SubCategoryRequest extends CategoryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'] = ['sometimes', 'nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('sub_categories', 'slug')->ignore($this->route('sub_category')?->id)];
        $rules['category_id'] = [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', Rule::exists('categories', 'id')];

        return $rules;
    }
}
