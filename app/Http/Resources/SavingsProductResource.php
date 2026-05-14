<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsProductResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'minimum_opening_balance' => (float) $this->minimum_opening_balance,
            'minimum_balance' => (float) $this->minimum_balance,
            'interest_rate' => (float) $this->interest_rate,
            'interest_posting_frequency' => $this->interest_posting_frequency,
            'withdrawal_charge' => (float) $this->withdrawal_charge,
            'deposit_charge' => (float) $this->deposit_charge,
            'monthly_maintenance_fee' => (float) $this->monthly_maintenance_fee,
            'account_number_prefix' => $this->account_number_prefix,
            'allows_overdraft' => (bool) $this->allows_overdraft,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
