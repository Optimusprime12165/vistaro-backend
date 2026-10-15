<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a setting (includes is_public and timestamps). The public API uses grouped plain values instead. */
class BusinessSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $this->typedValue(),
            'type' => $this->type->value,
            'group' => $this->group,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
