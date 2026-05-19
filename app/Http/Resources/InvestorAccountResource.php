<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestorAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'total_invested' => (float) $this->total_invested,
            'total_returns_earned' => (float) $this->total_returns_earned,
            'total_withdrawn' => (float) $this->total_withdrawn,
            'current_investment_balance' => (float) $this->current_investment_balance,
            'status' => $this->status,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'client_number' => $this->client->client_number,
                'full_name' => $this->client->full_name,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),
            'assigned_officer' => $this->whenLoaded('assignedOfficer', fn () => [
                'id' => $this->assignedOfficer->id,
                'name' => $this->assignedOfficer->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
