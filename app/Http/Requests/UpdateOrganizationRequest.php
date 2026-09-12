<?php

namespace App\Http\Requests;

use App\CurrencyRegistry;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->canPerform('manage-organization')
            || $user?->hasRole('super-admin')
            || $user?->hasRole('supervisor')
            || $user?->hasRole('organization-admin')
            || false;
    }

    protected function prepareForValidation(): void
    {
        $organization = $this->organization();

        $this->merge([
            'financial_year_start_month' => $this->input('financial_year_start_month', $organization?->financial_year_start_month ?? 'january'),
            'status' => $this->input('status', $organization?->status ?? 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', Rule::unique('organizations')->ignore($organization)],
            'registration_number' => ['nullable', 'string', 'max:191'],
            'tax_identification_number' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'logo' => ['nullable', 'string', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'url', 'max:191'],
            'social_links.x' => ['nullable', 'url', 'max:191'],
            'social_links.linkedin' => ['nullable', 'url', 'max:191'],
            'social_links.whatsapp' => ['nullable', 'string', 'max:80'],
            'logo_file' => ['nullable', 'image', 'max:2048'],
            'address' => ['nullable', 'string'],
            'currency' => ['required', 'string', 'size:3', Rule::in(array_keys(CurrencyRegistry::all()))],
            'financial_year_start_month' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:active,suspended,disabled'],
        ];
    }

    private function organization(): ?Organization
    {
        return $this->route('organization')
            ?: $this->user()?->organization
            ?: $this->user()?->branch?->organization
            ?: Organization::query()->orderBy('id')->first();
    }
}
