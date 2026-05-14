<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookEventResource extends JsonResource
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
            'source_type' => $this->source_type,
            'provider_name' => $this->provider_name,
            'provider_code' => $this->provider_code,
            'event_id' => $this->event_id,
            'duplicate_of_id' => $this->duplicate_of_id,
            'payload_hash' => $this->payload_hash,
            'signature_valid' => $this->signature_valid,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'error_message' => $this->error_message,
            'processing_result' => $this->processing_result,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
