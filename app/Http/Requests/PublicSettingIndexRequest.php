<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublicSettingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['group' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/']];
    }
}
