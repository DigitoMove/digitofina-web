<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\CurrencyDenomination;
use Illuminate\Validation\Rule;

class TransactionPreviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && ! $this->user()->isMember();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['teller', 'non-cash', 'savings-deposit', 'savings-withdrawal', 'savings-transfer', 'reversal'])],
            'amount' => ['required_unless:kind,reversal', 'nullable', 'numeric', 'min:100', CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'account_scope' => ['nullable', Rule::in(['client_account', 'general_ledger'])],
            'chart_account_id' => ['nullable', 'integer', 'min:1'],
            'transaction_type_id' => ['nullable', 'integer', 'min:1'],
            'from_savings_account_id' => ['nullable', 'integer', 'min:1'],
            'to_savings_account_id' => ['nullable', 'integer', 'min:1'],
            'approval_id' => ['required_if:kind,reversal', 'nullable', 'integer', 'min:1'],
            'gl_entry_type' => ['nullable', Rule::in(['debit', 'credit'])],
            'charge_mode' => ['nullable', Rule::in(['deduct_from_amount', 'add_to_amount'])],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'cheque', 'internal_transfer'])],
            'transaction_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
