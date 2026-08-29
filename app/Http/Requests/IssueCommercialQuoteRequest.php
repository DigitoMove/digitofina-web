<?php

namespace App\Http\Requests;

use App\Models\CommercialQuote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueCommercialQuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $quote = $this->route('commercialQuote');

        return $quote instanceof CommercialQuote && ($this->user()?->can('update', $quote) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'billing_type' => ['required', Rule::in(['subscription', 'one_time'])],
            'subscription_months' => ['nullable', 'integer', 'min:1', 'max:36', Rule::requiredIf($this->string('billing_type')->toString() === 'subscription')],
            'amount' => ['required', 'numeric', 'min:500'],
            'currency' => ['required', Rule::in(['UGX'])],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
