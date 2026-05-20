<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixedSavingsAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'principal_amount' => (float) $this->principal_amount,
            'start_date' => $this->start_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),
            'interest_rate' => (float) $this->interest_rate,
            'expected_interest' => (float) $this->expected_interest,
            'actual_interest' => (float) $this->actual_interest,
            'early_withdrawal_allowed' => (bool) $this->early_withdrawal_allowed,
            'early_withdrawal_penalty' => (float) $this->early_withdrawal_penalty,
            'status' => $this->status,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'client_number' => $this->client->client_number,
                'full_name' => $this->client->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
