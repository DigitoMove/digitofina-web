<?php

namespace App\Http\Requests;

use App\Models\CommercialQuote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommercialQuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', CommercialQuote::class) ?? false;
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
            'requested_scope' => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }
}
