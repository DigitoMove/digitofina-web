<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'investment_number' => $this->investment_number,
            'principal_amount' => (float) $this->principal_amount,
            'start_date' => $this->start_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),
            'expected_return_amount' => (float) $this->expected_return_amount,
            'actual_return_amount' => (float) $this->actual_return_amount,
            'current_balance' => (float) $this->current_balance,
            'return_rate' => (float) $this->return_rate,
            'return_method' => $this->return_method,
            'status' => $this->status,
            'investor_account' => $this->whenLoaded('investorAccount', fn () => [
                'id' => $this->investorAccount->id,
                'account_number' => $this->investorAccount->account_number,
            ]),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'client_number' => $this->client->client_number,
                'full_name' => $this->client->full_name,
            ]),
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'code' => $this->plan->code,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
