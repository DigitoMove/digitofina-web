<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvestmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->canPerform('manage-accounts') || $this->user()?->canPerform('manage-investments')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['fixed', 'recurring', 'group', 'project_based', 'profit_sharing', 'target_based'])],
            'minimum_amount' => ['required', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'gte:minimum_amount'],
            'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years'])],
            'expected_return_rate' => ['required', 'numeric', 'min:0'],
            'return_calculation_method' => ['required', Rule::in(['fixed_percentage', 'simple_interest', 'compound_interest', 'profit_share', 'manual_return_posting'])],
            'return_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'annually', 'at_maturity'])],
            'allow_top_ups' => ['sometimes', 'boolean'],
            'allow_early_withdrawal' => ['sometimes', 'boolean'],
            'early_withdrawal_penalty' => ['nullable', 'numeric', 'min:0'],
            'auto_reinvest_returns' => ['sometimes', 'boolean'],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ];
    }
}
