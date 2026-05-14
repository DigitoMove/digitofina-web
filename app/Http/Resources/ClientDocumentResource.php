<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientDocumentResource extends JsonResource
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
            'client_id' => $this->client_id,
            'type' => $this->type,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'content_hash' => $this->content_hash,
            'image_width' => $this->image_width,
            'image_height' => $this->image_height,
            'compression_metadata' => $this->compression_metadata ?? [],
            'local_uuid' => $this->local_uuid,
            'verification_status' => $this->verification_status,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => [
                'id' => $this->uploadedBy?->id,
                'name' => $this->uploadedBy?->name,
            ]),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => [
                'id' => $this->verifiedBy?->id,
                'name' => $this->verifiedBy?->name,
            ]),
            'captured_at' => $this->captured_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
