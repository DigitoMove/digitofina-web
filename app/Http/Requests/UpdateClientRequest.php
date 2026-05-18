<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-clients') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $client = $this->route('client');

        $this->merge([
            'client_type' => $this->input('client_type', $client?->client_type ?? 'individual'),
            'kyc_status' => $this->input('kyc_status', $client?->kyc_status ?? 'pending'),
            'status' => $this->input('status', $client?->status ?? 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $client = $this->route('client');

        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'client_type' => ['required', 'in:individual,group,business,institution'],
            'first_name' => ['required_unless:client_type,group,business,institution', 'nullable', 'string', 'max:191'],
            'middle_name' => ['nullable', 'string', 'max:191'],
            'last_name' => ['required_unless:client_type,group,business,institution', 'nullable', 'string', 'max:191'],
            'gender' => ['nullable', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'national_id' => ['nullable', 'string', 'max:80', Rule::unique('clients', 'national_id')->ignore($client)],
            'phone' => ['required', 'string', 'max:40'],
            'alternative_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'occupation' => ['nullable', 'string', 'max:191'],
            'employer_name' => ['nullable', 'string', 'max:191'],
            'marital_status' => ['nullable', 'string', 'max:40'],
            'next_of_kin_name' => ['nullable', 'string', 'max:191'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'village' => ['nullable', 'string', 'max:191'],
            'parish' => ['nullable', 'string', 'max:191'],
            'sub_county' => ['nullable', 'string', 'max:191'],
            'district' => ['nullable', 'string', 'max:191'],
            'region' => ['nullable', 'string', 'max:191'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'employment_status' => ['nullable', 'string', 'max:191'],
            'business_name' => ['nullable', 'string', 'max:191'],
            'business_details' => ['nullable', 'string'],
            'kyc_status' => ['nullable', 'in:pending,verified,rejected'],
            'risk_rating' => ['nullable', 'in:low,medium,high'],
            'status' => ['nullable', 'in:pending,active,dormant,suspended,blacklisted,closed'],
            'kyc_checklist' => ['array'],
            'kyc_checklist.*' => ['string', 'max:80'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
