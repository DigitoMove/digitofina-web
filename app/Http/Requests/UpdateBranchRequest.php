<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-branches') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status', $this->route('branch')?->status ?? 'active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('branches', 'code')->ignore($this->route('branch'))],
            'name' => ['required', 'string', 'max:191'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $organizationId = $this->integer('organization_id') ?: $this->user()?->organization_id;

            if ($organizationId && ! $this->user()?->canAccessOrganization($organizationId)) {
                $validator->errors()->add('organization_id', 'You cannot move branches to this organization.');
            }
        });
    }
}
