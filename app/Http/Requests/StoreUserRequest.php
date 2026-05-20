<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-users') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:40', 'unique:users,phone', 'unique:clients,phone'],
            'position' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,suspended,disabled'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $role = Role::query()->find($this->integer('role_id'));
            $roleSlug = $role?->slug;
            $user = $this->user();
            $organizationId = $this->integer('organization_id') ?: $user?->organization_id;

            if ($organizationId && ! $user?->canAccessOrganization($organizationId)) {
                $validator->errors()->add('organization_id', 'You cannot create users for this organization.');
            }

            if ($roleSlug === 'super-admin') {
                return;
            }

            if ($roleSlug === 'organization-admin') {
                if (! $organizationId) {
                    $validator->errors()->add('organization_id', 'Organization admins must belong to an organization.');
                }

                return;
            }

            if (! $this->filled('branch_id')) {
                $validator->errors()->add('branch_id', 'Branch is required for this role.');
            }
        });
    }
}
