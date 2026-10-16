<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff Management row. Credentials (password / hash / tokens) are never part of it. @mixin \App\Models\Admin */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->isSuperAdmin() ? 'Super Admin' : 'Staff',
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'is_protected' => $this->isSuperAdmin(),                         // the Super Admin cannot be edited / deactivated here
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
        ];
    }
}
