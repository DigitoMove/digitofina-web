<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledLoanSavingsDeductionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canPerform('post-repayments') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['nullable', 'integer', 'exists:accounts,id', 'required_without:savings_account_id'],
            'savings_account_id' => ['nullable', 'integer', 'exists:savings_accounts,id', 'required_without:account_id'],
            'loan_id' => ['required', 'integer', 'exists:loans,id'],
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'frequency' => ['required', 'string', 'in:daily,weekly,biweekly,monthly'],
            'next_run_at' => ['required', 'date'],
        ];
    }
}
