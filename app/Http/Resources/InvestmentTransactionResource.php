<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            'payment_method' => $this->payment_method,
            'narration' => $this->narration,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'status' => $this->status,
            'investor_account' => $this->whenLoaded('investorAccount', fn () => [
                'id' => $this->investorAccount->id,
                'account_number' => $this->investorAccount->account_number,
            ]),
            'investment_account' => $this->whenLoaded('investmentAccount', fn () => [
                'id' => $this->investmentAccount->id,
                'investment_number' => $this->investmentAccount->investment_number,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
