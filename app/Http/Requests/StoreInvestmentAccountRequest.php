<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvestmentAccountRequest extends FormRequest
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
            'investor_account_id' => ['required', 'exists:investor_accounts,id'],
            'investment_plan_id' => ['required', 'exists:investment_plans,id'],
            'sponsor_client_id' => ['nullable', 'exists:clients,id'],
            'unit_quantity' => ['nullable', 'integer', 'min:1'],
            'principal_amount' => ['required_without:unit_quantity', 'numeric', 'gt:0'],
            'target_duration_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'client_investment_details' => ['nullable', 'array'],
            'client_investment_details.*' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'payment_method' => ['required', 'in:cash,mobile_money,bank,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
