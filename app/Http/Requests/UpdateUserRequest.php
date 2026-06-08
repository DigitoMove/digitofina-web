<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-users') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', $this->route('user')?->status ?? 'active'),
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
            'password' => ['nullable', 'confirmed', Password::defaults()],
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
                $validator->errors()->add('organization_id', 'You cannot move users to this organization.');
            }

            if (($roleSlug === 'super-admin' || $this->route('user')?->hasRole('super-admin')) && ! $user?->hasRole('super-admin')) {
                $validator->errors()->add('role_id', 'Only a super admin can assign or edit the Super Admin role.');

                return;
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
