<?php

namespace App\Http\Requests\Admin;

use App\Enums\SettingType;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class SettingIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function filterRules(): array
    {
        return [
            'group' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'is_public' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::in(SettingType::values())],
        ];
    }
}
