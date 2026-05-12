<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsTransactionResource extends JsonResource
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
            'reference' => $this->reference,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'charges' => (float) $this->charges,
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            'payment_method' => $this->payment_method,
            'narration' => $this->narration,
            'status' => $this->status,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'account_number' => $this->account->account_number,
            ]),
            'teller' => $this->whenLoaded('teller', fn () => [
                'id' => $this->teller?->id,
                'name' => $this->teller?->name,
            ]),
        ];
    }
}
