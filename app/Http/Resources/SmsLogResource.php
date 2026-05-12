<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient' => $this->recipient,
            'type' => $this->type,
            'message' => $this->message,
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'status' => $this->status,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'provider' => $this->whenLoaded('provider', fn () => [
                'id' => $this->provider?->id,
                'name' => $this->provider?->name,
                'code' => $this->provider?->code,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
