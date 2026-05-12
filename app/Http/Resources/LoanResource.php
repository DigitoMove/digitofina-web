<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
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
            'loan_number' => $this->loan_number,
            'requested_amount' => (float) $this->requested_amount,
            'approved_amount' => $this->approved_amount !== null ? (float) $this->approved_amount : null,
            'principal_balance' => (float) $this->principal_balance,
            'interest_balance' => (float) $this->interest_balance,
            'fees_balance' => (float) $this->fees_balance,
            'penalty_balance' => (float) $this->penalty_balance,
            'interest_rate' => (float) $this->interest_rate,
            'term_periods' => $this->term_periods,
            'repayment_frequency' => $this->repayment_frequency,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'application_date' => $this->application_date?->toDateString(),
            'submitted_at' => $this->submitted_at?->toDateString(),
            'approved_at' => $this->approved_at?->toDateString(),
            'disbursed_at' => $this->disbursed_at?->toDateString(),
            'client' => new ClientResource($this->whenLoaded('client')),
            'product' => new LoanProductResource($this->whenLoaded('product')),
            'schedules' => LoanScheduleResource::collection($this->whenLoaded('schedules')),
            'repayments' => LoanRepaymentResource::collection($this->whenLoaded('repayments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
