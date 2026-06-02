<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('manage-clients') ?? false;
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
        $organizationId = $this->targetOrganizationId();
        $branchExists = Rule::exists('branches', 'id')
            ->when($organizationId, fn ($rule) => $rule->where('organization_id', $organizationId));
        $clientExists = Rule::exists('clients', 'id')
            ->when($organizationId, fn ($rule) => $rule->where('organization_id', $organizationId));

        return [
            'branch_id' => ['required', $branchExists],
            'group_number' => ['required', 'string', 'max:50', Rule::unique('client_groups', 'group_number')],
            'name' => ['required', 'string', 'max:191'],
            'registration_number' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'meeting_day' => ['nullable', 'string', 'max:30'],
            'chairperson_client_id' => ['nullable', $clientExists],
            'secretary_client_id' => ['nullable', $clientExists],
            'treasurer_client_id' => ['nullable', $clientExists],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', $clientExists],
            'status' => ['nullable', 'in:active,inactive,suspended,closed'],
        ];
    }

    private function targetOrganizationId(): ?int
    {
        $branchOrganizationId = Branch::query()
            ->whereKey($this->integer('branch_id'))
            ->value('organization_id');

        return $branchOrganizationId
            ? (int) $branchOrganizationId
            : (app(TenantContext::class)->id() ?: $this->user()?->organization_id);
    }
}
