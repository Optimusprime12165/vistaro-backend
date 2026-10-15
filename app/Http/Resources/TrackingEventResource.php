<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ShipmentEvent */
class TrackingEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['status' => $this->status, 'location' => $this->location, 'description' => $this->description, 'at' => $this->occurred_at->toIso8601String()];
    }
}
