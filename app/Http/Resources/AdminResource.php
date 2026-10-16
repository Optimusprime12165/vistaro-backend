<?php

namespace App\Http\Resources;

use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Admin  (password is never part of any response) */
class AdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'is_active' => $this->is_active,
            'permissions' => AdminPermissions::for($this->role),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
