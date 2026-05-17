<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('post-repayments') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'payment_method' => ['required', Rule::in(['cash', 'mobile_money', 'bank', 'savings_deduction', 'cheque', 'internal_transfer'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'paid_at' => ['required', 'date'],
        ];
    }
}
