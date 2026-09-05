<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\Role;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('organization-admin')
            || $this->user()?->canPerform('manage-users') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', $this->route('user')?->status ?? 'active'),
            'change_password' => $this->boolean('change_password'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role_id' => ['required', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['required', 'string', 'max:40', Rule::unique('users', 'phone')->ignore($user)],
            'position' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,suspended,disabled'],
            'change_password' => ['boolean'],
            'password' => ['nullable', Rule::requiredIf($this->boolean('change_password')), 'confirmed', Password::defaults()],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $role = Role::query()->find($this->integer('role_id'));
            $roleSlug = $role?->slug;
            $user = $this->user();
            $submittedOrganizationId = $this->integer('organization_id') ?: null;
            $organizationId = $user?->hasGlobalOrganizationAccess()
                ? ($submittedOrganizationId ?: $this->route('user')?->organization_id)
                : ($user?->organization_id ? (int) $user->organization_id : null);
            $organizationId = $organizationId ? (int) $organizationId : null;

            if ($submittedOrganizationId && ! $user?->canAccessOrganization($submittedOrganizationId)) {
                $validator->errors()->add('organization_id', 'You cannot move users to this organization.');
            }

            if ($roleSlug === RoleCatalog::SUPER_ADMIN_SLUG) {
                $validator->errors()->add('role_id', 'The Super Admin role cannot be assigned through staff management.');

                return;
            }

            if ($roleSlug === RoleCatalog::MEMBER_SLUG) {
                $validator->errors()->add('role_id', 'Client portal accounts are created and managed from the Clients section.');

                return;
            }

            if ($this->route('user')?->hasRole(RoleCatalog::SUPER_ADMIN_SLUG) && ! $user?->hasRole(RoleCatalog::SUPER_ADMIN_SLUG)) {
                $validator->errors()->add('role_id', 'Only a super admin can edit a Super Admin account.');

                return;
            }

            if ($role && ! $role->isAssignableToOrganization($organizationId)) {
                $validator->errors()->add('role_id', 'Select a role belonging to the selected organization.');
            }

            if ($roleSlug === 'organization-admin' && ! $organizationId) {
                $validator->errors()->add('organization_id', 'Organization admins must belong to an organization.');
            }

            $this->validateBranchScope($validator, $organizationId);
        });
    }

    private function validateBranchScope($validator, ?int $organizationId): void
    {
        $branchId = $this->integer('branch_id') ?: null;

        if ($branchId === null) {
            return;
        }

        $branchBelongsToOrganization = $organizationId !== null
            && Branch::query()->whereKey($branchId)->where('organization_id', $organizationId)->exists();

        if (! $branchBelongsToOrganization || ! $this->user()?->canAccessBranch($branchId, $organizationId)) {
            $validator->errors()->add('branch_id', 'Select a branch belonging to the selected organization.');
        }
    }
}
