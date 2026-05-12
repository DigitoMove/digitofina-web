<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-organization') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', Rule::unique('organizations')->ignore($organization)],
            'registration_number' => ['nullable', 'string', 'max:191'],
            'tax_identification_number' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'max:10'],
            'financial_year_start_month' => ['required', 'string', 'max:20'],
            'status' => ['required', 'in:active,suspended,disabled'],
        ];
    }
}
