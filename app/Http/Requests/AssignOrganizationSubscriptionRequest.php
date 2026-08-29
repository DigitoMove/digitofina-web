<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOrganizationSubscriptionRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', Rule::exists('organizations', 'id')->where('status', 'active')],
            'billing_type' => ['required', Rule::in(['subscription', 'one_time'])],
            'assignment_basis' => ['required', Rule::in(['assigned', 'paid'])],
            'subscription_months' => ['nullable', 'required_if:billing_type,subscription', 'integer', 'min:1', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
