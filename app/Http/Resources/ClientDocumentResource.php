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
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'verification_status' => $this->verification_status,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => [
                'id' => $this->uploadedBy?->id,
                'name' => $this->uploadedBy?->name,
            ]),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => [
                'id' => $this->verifiedBy?->id,
                'name' => $this->verifiedBy?->name,
            ]),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
