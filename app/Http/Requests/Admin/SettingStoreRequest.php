<?php

namespace App\Http\Requests\Admin;

use App\Enums\SettingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /admin/settings. Structure only; the typed value, secret scan and known-key range checks are enforced by BusinessSettingsService. */
class SettingStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){0,4}$/', Rule::unique('business_settings', 'key')],
            'type' => ['required', 'string', Rule::in(SettingType::values())],
            'value' => ['present'],
            'group' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.unique' => 'A setting with this key already exists.',
            'key.regex' => 'The key must be lowercase letters, digits and underscores with optional dot-separated parts.',
            'group.regex' => 'The group must be lowercase letters, digits and underscores.',
        ];
    }
}
