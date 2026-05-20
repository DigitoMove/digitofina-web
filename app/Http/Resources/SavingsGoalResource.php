<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'goal_name' => $this->goal_name ?? $this->name,
            'target_amount' => (float) $this->target_amount,
            'current_amount' => (float) ($this->current_amount ?? $this->current_saved_amount),
            'current_saved_amount' => (float) $this->current_saved_amount,
            'start_date' => $this->start_date?->toDateString(),
            'target_date' => $this->target_date?->toDateString(),
            'contribution_frequency' => $this->contribution_frequency,
            'expected_contribution_amount' => (float) $this->expected_contribution_amount,
            'status' => $this->status,
            'percentage_achieved' => $this->percentage_achieved,
            'remaining_amount' => $this->remaining_amount,
            'days_remaining' => $this->days_remaining,
            'monthly_required_contribution' => $this->monthly_required_contribution,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'client_number' => $this->client->client_number,
                'full_name' => $this->client->full_name,
            ]),
            'linked_savings_account' => $this->whenLoaded('linkedSavingsAccount', fn () => [
                'id' => $this->linkedSavingsAccount->id,
                'account_number' => $this->linkedSavingsAccount->account_number,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
