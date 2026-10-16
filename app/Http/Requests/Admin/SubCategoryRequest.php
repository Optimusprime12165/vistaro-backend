<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class SubCategoryRequest extends CategoryRequest
{
    /** A sub-category name is unique inside its category. */
    protected function uniqueName(): \Illuminate\Validation\Rules\Unique
    {
        $categoryId = $this->input('category_id', $this->route('sub_category')?->category_id);

        return Rule::unique('sub_categories', 'name')->where('category_id', $categoryId)->ignore($this->route('sub_category')?->id);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'] = ['sometimes', 'nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('sub_categories', 'slug')->ignore($this->route('sub_category')?->id)];
        $rules['category_id'] = [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', Rule::exists('categories', 'id')];

        return $rules;
    }
}
