<?php

namespace App\Http\Requests;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('import-data') || $this->user()?->canPerform('manage-settings');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['clients', 'savings_accounts', 'opening_balances', 'existing_loans', 'repayment_schedules', 'chart_accounts'])],
            'organization_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id'),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(function ($query): void {
                    $user = $this->user();

                    if (! $user?->hasGlobalOrganizationAccess() && $user?->organization_id) {
                        $query->where('organization_id', $user->organization_id);
                    }
                }),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $user = $this->user();
            $organizationId = $this->integer('organization_id') ?: null;
            $branchId = $this->integer('branch_id') ?: null;

            if ($organizationId && ! $user?->canAccessOrganization($organizationId)) {
                $validator->errors()->add('organization_id', 'You cannot import data for this organization.');
            }

            if (! $branchId) {
                return;
            }

            $branch = Branch::find($branchId);

            if (! $branch || ! $user?->canAccessBranch($branch->id, $branch->organization_id)) {
                $validator->errors()->add('branch_id', 'You cannot import data for this branch.');

                return;
            }

            if ($organizationId && (int) $branch->organization_id !== $organizationId) {
                $validator->errors()->add('branch_id', 'The selected branch does not belong to the selected organization.');
            }
        });
    }
}
