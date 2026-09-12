<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanScheduleResource extends JsonResource
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
            'installment_number' => $this->installment_number,
            'due_date' => $this->due_date?->toDateString(),
            'principal_due' => (float) $this->principal_due,
            'interest_due' => (float) $this->interest_due,
            'fees_due' => (float) $this->fees_due,
            'penalty_due' => (float) $this->penalty_due,
            'total_due' => (float) $this->total_due,
            'principal_paid' => (float) $this->principal_paid,
            'interest_paid' => (float) $this->interest_paid,
            'fees_paid' => (float) $this->fees_paid,
            'penalty_paid' => (float) $this->penalty_paid,
            'total_paid' => (float) $this->total_paid,
            'outstanding_amount' => (float) $this->outstanding_amount,
            'days_overdue' => $this->days_overdue,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'loan' => $this->whenLoaded('loan', fn (): ?array => $this->loan ? [
                'id' => $this->loan->id,
                'loan_number' => $this->loan->loan_number,
                'status' => $this->loan->status,
                'client' => $this->loan->relationLoaded('client') && $this->loan->client ? [
                    'id' => $this->loan->client->id,
                    'name' => $this->loan->client->full_name,
                    'client_number' => $this->loan->client->client_number,
                    'phone' => $this->loan->client->phone,
                ] : null,
            ] : null),
        ];
    }
}
