<?php

namespace App\Http\Resources;

use App\Support\ApiQuery;
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
        $data = [
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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! ApiQuery::wantsDetailedResource($request)) {
            return $data;
        }

        return $data + [
            'source_of_repayment' => $this->source_of_repayment,
            'business_details' => $this->business_details,
            'existing_loans' => $this->existing_loans,
            'risk_score' => $this->risk_score !== null ? (float) $this->risk_score : null,
            'credit_score' => $this->credit_score !== null ? (float) $this->credit_score : null,
            'savings_balance_at_application' => $this->savings_balance_at_application !== null ? (float) $this->savings_balance_at_application : null,
            'required_savings_amount' => $this->required_savings_amount !== null ? (float) $this->required_savings_amount : null,
            'schedules' => LoanScheduleResource::collection($this->whenLoaded('schedules')),
            'repayments' => LoanRepaymentResource::collection($this->whenLoaded('repayments')),
        ];
    }
}
