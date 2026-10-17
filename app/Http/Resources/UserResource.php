<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'mobile' => $this->mobile,
            'mobile_verified' => $this->mobile_verified_at !== null,
            'profile_complete' => $this->name !== null && $this->name !== '',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
