<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestmentPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type,
            'min_amount' => (float) ($this->min_amount ?? $this->minimum_amount),
            'max_amount' => $this->max_amount !== null || $this->maximum_amount !== null ? (float) ($this->max_amount ?? $this->maximum_amount) : null,
            'minimum_amount' => (float) $this->minimum_amount,
            'maximum_amount' => $this->maximum_amount !== null ? (float) $this->maximum_amount : null,
            'duration' => (int) $this->duration,
            'duration_unit' => $this->duration_unit,
            'expected_return_rate' => (float) $this->expected_return_rate,
            'return_method' => $this->return_method ?? $this->return_calculation_method,
            'return_calculation_method' => $this->return_calculation_method,
            'return_frequency' => $this->return_frequency,
            'allow_topups' => (bool) ($this->allow_topups ?? $this->allow_top_ups),
            'allow_top_ups' => (bool) $this->allow_top_ups,
            'allow_early_withdrawal' => (bool) $this->allow_early_withdrawal,
            'early_withdrawal_penalty_type' => $this->early_withdrawal_penalty_type,
            'early_withdrawal_penalty_value' => (float) ($this->early_withdrawal_penalty_value ?? $this->early_withdrawal_penalty),
            'early_withdrawal_penalty' => (float) $this->early_withdrawal_penalty,
            'auto_reinvest' => (bool) ($this->auto_reinvest ?? $this->auto_reinvest_returns),
            'auto_reinvest_returns' => (bool) $this->auto_reinvest_returns,
            'risk_level' => $this->risk_level,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
