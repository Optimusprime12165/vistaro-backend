<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LoginEvent */
class LoginEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'user_id' => $this->user_id, 'name' => $this->user?->name, 'mobile' => $this->mobile,
            'method' => $this->method, 'ip' => $this->ip, 'user_agent' => $this->user_agent, 'at' => $this->created_at->toIso8601String(),
        ];
    }
}
