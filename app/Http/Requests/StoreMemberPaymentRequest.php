<?php

namespace App\Http\Requests;

use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMemberPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->client()->exists() ?? false;
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
            'phone' => ['required', 'string', 'max:40'],
            'provider_id' => ['nullable', 'integer', 'exists:payment_providers,id'],
        ];
    }
}
