<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanApplicationFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('disburse-loans') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'application_fee_override' => ['nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'application_fee_override_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
