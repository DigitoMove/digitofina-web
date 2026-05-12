<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-loans') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', Rule::exists('clients', 'id')->where('status', 'active')],
            'loan_product_id' => ['required', Rule::exists('loan_products', 'id')->where('status', 'active')],
            'savings_account_id' => ['nullable', Rule::exists('accounts', 'id')],
            'requested_amount' => ['required', 'numeric', 'min:1'],
            'term_periods' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['required', Rule::in(['daily', 'weekly', 'bi-weekly', 'monthly', 'quarterly'])],
            'purpose' => ['required', 'string', 'max:255'],
            'source_of_repayment' => ['nullable', 'string', 'max:255'],
            'business_details' => ['nullable', 'string'],
            'existing_loans' => ['nullable', 'string'],
            'risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'credit_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'collateral_details' => ['nullable', 'string'],
            'credit_officer_comments' => ['nullable', 'string'],
        ];
    }
}
