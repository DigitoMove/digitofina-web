<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-loans') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', 'active'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', Rule::unique('loan_products', 'code')],
            'description' => ['nullable', 'string'],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['required', 'numeric', 'gt:min_amount'],
            'min_term' => ['required', 'integer', 'min:1'],
            'max_term' => ['required', 'integer', 'gte:min_term'],
            'term_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years'])],
            'annual_interest_rate' => ['required', 'numeric', 'min:0'],
            'interest_method' => ['required', Rule::in(['flat', 'declining_balance', 'reducing_balance_equal_installments', 'interest_only'])],
            'interest_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'annually'])],
            'repayment_frequency' => ['required', Rule::in(['daily', 'weekly', 'bi-weekly', 'monthly', 'quarterly'])],
            'default_term_months' => ['required', 'integer', 'min:1'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'penalty_type' => ['required', Rule::in(['fixed', 'percentage', 'daily', 'weekly', 'monthly'])],
            'penalty_rate' => ['required', 'numeric', 'min:0'],
            'processing_fee' => ['nullable', 'numeric', 'min:0'],
            'insurance_fee' => ['nullable', 'numeric', 'min:0'],
            'application_fee' => ['nullable', 'numeric', 'min:0'],
            'required_savings_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'require_guarantors' => ['nullable', 'boolean'],
            'minimum_guarantors' => ['nullable', 'integer', 'min:0'],
            'require_collateral' => ['nullable', 'boolean'],
            'auto_deduct_repayment_from_savings' => ['nullable', 'boolean'],
            'loan_account_number_prefix' => ['required', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
