<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

class LoginEventIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'created_at'];
    }

    protected function filterRules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
