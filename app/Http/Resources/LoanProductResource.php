<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanProductResource extends JsonResource
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
            'min_amount' => (float) $this->min_amount,
            'max_amount' => (float) $this->max_amount,
            'min_term' => $this->min_term,
            'max_term' => $this->max_term,
            'term_unit' => $this->term_unit,
            'annual_interest_rate' => (float) $this->annual_interest_rate,
            'is_interest_free' => (bool) $this->is_interest_free,
            'interest_method' => $this->interest_method,
            'repayment_frequency' => $this->repayment_frequency,
            'penalty_type' => $this->penalty_type,
            'penalty_rate' => (float) $this->penalty_rate,
            'processing_fee' => (float) $this->processing_fee,
            'required_savings_percentage' => (float) $this->required_savings_percentage,
            'require_guarantors' => (bool) $this->require_guarantors,
            'minimum_guarantors' => $this->minimum_guarantors,
            'require_collateral' => (bool) $this->require_collateral,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
