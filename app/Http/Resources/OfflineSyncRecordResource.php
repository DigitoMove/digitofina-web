<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfflineSyncRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_type' => $this->resource_type,
            'action' => $this->action,
            'local_temp_id' => $this->local_temp_id,
            'status' => $this->status,
            'server_record' => [
                'type' => $this->server_record_type,
                'id' => $this->server_record_id,
            ],
            'client_updated_at' => $this->client_updated_at?->toIso8601String(),
            'errors' => $this->validation_errors,
            'conflict' => $this->conflict_data,
            'response' => $this->response_data,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
