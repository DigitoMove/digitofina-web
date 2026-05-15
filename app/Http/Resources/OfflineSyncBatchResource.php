<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfflineSyncBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_batch_id' => $this->client_batch_id,
            'device_name' => $this->device_name,
            'status' => $this->status,
            'summary' => [
                'total' => $this->total_records,
                'accepted' => $this->accepted_records,
                'conflicts' => $this->conflict_records,
                'failed' => $this->failed_records,
            ],
            'records' => OfflineSyncRecordResource::collection($this->whenLoaded('records')),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
