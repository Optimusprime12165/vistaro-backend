<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class CustomerAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'mobile' => $this->mobile, 'email' => $this->email,
            'status' => $this->status->value, 'orders_count' => $this->whenCounted('orders'),
            'last_login_at' => $this->last_login_at?->toIso8601String(), 'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
