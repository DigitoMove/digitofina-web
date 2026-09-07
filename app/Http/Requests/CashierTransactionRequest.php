<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashierTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isTellerCashier() && $this->user()->organization_id && $this->user()->branch_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'drawer_session_id' => ['required', 'integer', 'min:1'],
            'account_scope' => ['required', Rule::in(['client_account', 'general_ledger'])],
            'account_number' => ['nullable', 'required_if:account_scope,client_account', 'string', 'max:100'],
            'transaction_type_id' => ['nullable', 'required_if:account_scope,client_account', 'integer', 'min:1'],
            'chart_account_id' => ['nullable', 'required_if:account_scope,general_ledger', 'integer', 'min:1'],
            'gl_entry_type' => ['nullable', Rule::in(['debit', 'credit'])],
            'charge_mode' => ['nullable', Rule::in(['deduct_from_amount', 'add_to_amount'])],
            'amount' => ['required', 'numeric', 'min:100', CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cheque_number' => ['nullable', 'string', 'max:191'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
