<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MakerCheckerRequestResource extends JsonResource
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
            'action' => $this->action,
            'required_permission' => $this->required_permission,
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'status' => $this->status,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'old_values' => $this->old_values,
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->id,
                'name' => $this->branch?->name,
                'code' => $this->branch?->code,
            ]),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => [
                'id' => $this->requestedBy?->id,
                'name' => $this->requestedBy?->name,
            ]),
            'checked_by' => $this->whenLoaded('checkedBy', fn () => [
                'id' => $this->checkedBy?->id,
                'name' => $this->checkedBy?->name,
            ]),
            'checker_comments' => $this->checker_comments,
            'checked_at' => $this->checked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
