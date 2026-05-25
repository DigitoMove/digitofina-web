<?php

namespace App\Http\Resources;

use App\Services\InvestmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $earlyWithdrawalQuote = app(InvestmentService::class)->earlyWithdrawalQuote($this->resource);

        return [
            'id' => $this->id,
            'investment_number' => $this->investment_number,
            'principal_amount' => (float) $this->principal_amount,
            'unit_quantity' => (int) $this->unit_quantity,
            'unit_value' => (float) $this->unit_value,
            'unit_label' => $this->unit_label,
            'start_date' => $this->start_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),
            'target_duration_months' => $this->target_duration_months,
            'progress_percentage' => $this->progressPercentage(),
            'expected_return_amount' => (float) $this->expected_return_amount,
            'actual_return_amount' => (float) $this->actual_return_amount,
            'current_balance' => (float) $this->current_balance,
            'locked_amount' => (float) $this->locked_amount,
            'return_rate' => (float) $this->return_rate,
            'monthly_maintenance_fee' => (float) $this->monthly_maintenance_fee,
            'maintenance_fee_paid' => (float) $this->maintenance_fee_paid,
            'return_method' => $this->return_method,
            'client_investment_details' => $this->client_investment_details ?? [],
            'early_withdrawal_quote' => $earlyWithdrawalQuote,
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
            'sponsor_client' => $this->whenLoaded('sponsorClient', fn () => $this->sponsorClient ? [
                'id' => $this->sponsorClient->id,
                'client_number' => $this->sponsorClient->client_number,
                'full_name' => $this->sponsorClient->full_name,
            ] : null),
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'code' => $this->plan->code,
                'unit_label' => $this->plan->unit_label,
                'unit_value' => (float) $this->plan->unit_value,
                'monthly_maintenance_fee' => (float) $this->plan->monthly_maintenance_fee,
                'min_holding_months' => (int) ($this->plan->min_holding_months ?? 36),
                'max_holding_months' => (int) ($this->plan->max_holding_months ?? 60),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
