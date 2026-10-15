<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

class CustomerIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'name', 'created_at', 'last_login_at'];
    }

    protected function filterRules(): array
    {
        return ['status' => ['sometimes', Rule::enum(AccountStatus::class)]];
    }
}
