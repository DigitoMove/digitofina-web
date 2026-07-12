<?php

namespace App\Http\Requests;

use App\Services\LedgerChargeService;
use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNonCashTransactionRequest extends FormRequest
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
            'account_id' => ['required', 'exists:accounts,id'],
            'chart_account_id' => ['required', 'exists:chart_accounts,id'],
            'transaction_type_id' => ['required', 'exists:transaction_types,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'charges' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'charge_mode' => ['nullable', Rule::in(array_keys(LedgerChargeService::modes()))],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:1000'],
        ];
    }
}
