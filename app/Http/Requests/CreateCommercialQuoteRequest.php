<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCommercialQuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('status', 'active')],
            'billing_type' => ['required', Rule::in(['subscription', 'one_time'])],
            'subscription_months' => ['nullable', 'integer', 'min:1', 'max:36', Rule::requiredIf($this->string('billing_type')->toString() === 'subscription')],
            'requested_scope' => ['required', 'string', 'min:20', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:500'],
            'currency' => ['required', Rule::in(['UGX'])],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
