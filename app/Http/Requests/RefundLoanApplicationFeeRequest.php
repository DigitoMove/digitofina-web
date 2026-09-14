<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;

class RefundLoanApplicationFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('disburse-loans') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:100', CurrencyDenomination::validationRule()],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
