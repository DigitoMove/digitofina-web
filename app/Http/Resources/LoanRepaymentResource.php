<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanRepaymentResource extends JsonResource
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
            'amount' => (float) $this->amount,
            'penalty_paid' => (float) $this->penalty_paid,
            'fees_paid' => (float) $this->fees_paid,
            'interest_paid' => (float) $this->interest_paid,
            'principal_paid' => (float) $this->principal_paid,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toDateString(),
            'received_by' => $this->whenLoaded('receivedBy', fn () => [
                'id' => $this->receivedBy?->id,
                'name' => $this->receivedBy?->name,
            ]),
        ];
    }
}
