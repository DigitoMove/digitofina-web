<?php

namespace App\Http\Requests;

use App\Services\TenantContext;
use App\Support\RoleCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientPortalAccessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $client = $this->route('client');

        $user = $this->user();

        if (! $client || ! $user?->hasAnyRole([RoleCatalog::SUPER_ADMIN_SLUG, 'organization-admin'])) {
            return false;
        }

        if ($user->hasRole('organization-admin')) {
            return (int) $user->organization_id === (int) $client->organization_id;
        }

        $tenant = app(TenantContext::class);

        return ! $tenant->isScoped() || (int) $tenant->id() === (int) $client->organization_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
