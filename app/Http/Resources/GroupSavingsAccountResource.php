<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupSavingsAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contribution_amount' => (float) $this->contribution_amount,
            'contribution_frequency' => $this->contribution_frequency,
            'meeting_day' => $this->meeting_day,
            'group_savings_balance' => (float) $this->group_savings_balance,
            'status' => $this->status,
            'client_group' => $this->whenLoaded('clientGroup', fn () => [
                'id' => $this->clientGroup->id,
                'name' => $this->clientGroup->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
