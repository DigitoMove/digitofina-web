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
            'principal_amount' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date'],
            'payment_method' => ['required', 'in:cash,mobile_money,bank,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
