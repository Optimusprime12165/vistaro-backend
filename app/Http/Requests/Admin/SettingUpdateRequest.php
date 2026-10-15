<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /admin/settings/{key}. The key and type are immutable (prohibited here): create a new setting instead. */
class SettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['prohibited'],
            'type' => ['prohibited'],
            'value' => ['sometimes', 'present'],
            'group' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($this->only(['value', 'group', 'description', 'is_public']) === []) {
                $v->errors()->add('value', 'Provide value, group, description or is_public to change.');
            }
        }];
    }

    /** @return array<string,mixed> */
    public function changes(): array
    {
        return $this->safe()->only(['value', 'group', 'description', 'is_public']);
    }
}
