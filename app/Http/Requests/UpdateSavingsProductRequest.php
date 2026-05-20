<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavingsProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-accounts') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', $this->route('savings_product')?->status ?? 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:40', Rule::unique('savings_products', 'code')->ignore($this->route('savings_product'))],
            'savings_type' => ['required', 'in:ordinary,target,fixed,group,emergency,child_student,business'],
            'minimum_opening_balance' => ['required', 'numeric', 'min:0'],
            'minimum_balance' => ['required', 'numeric', 'min:0'],
            'interest_rate' => ['required', 'numeric', 'min:0'],
            'interest_posting_frequency' => ['required', 'in:daily,weekly,monthly,quarterly,annually'],
            'withdrawal_charge' => ['required', 'numeric', 'min:0'],
            'deposit_charge' => ['required', 'numeric', 'min:0'],
            'monthly_maintenance_fee' => ['required', 'numeric', 'min:0'],
            'account_number_prefix' => ['required', 'string', 'max:20'],
            'allows_overdraft' => ['boolean'],
            'supports_goals' => ['boolean'],
            'supports_fixed_savings' => ['boolean'],
            'supports_group_savings' => ['boolean'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
