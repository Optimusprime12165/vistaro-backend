<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

class StaffIndexRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function sortFields(): array
    {
        return ['id', 'name', 'email', 'last_login_at', 'created_at'];
    }

    protected function filterRules(): array
    {
        return ['is_active' => ['sometimes', 'boolean'], 'role' => ['sometimes', 'in:super_admin,staff']];
    }
}
