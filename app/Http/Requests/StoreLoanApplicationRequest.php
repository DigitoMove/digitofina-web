<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\User;
use App\Services\TenantContext;
use App\Support\CurrencyDenomination;
use App\Support\RoleCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->canCreateLoanApplications() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'loan_type' => $this->input('loan_type', 'standard'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id() ?: $this->user()?->organization_id;

        return [
            'client_id' => ['required', Rule::exists('clients', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'loan_product_id' => ['required', Rule::exists('loan_products', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'loan_type' => ['required', Rule::in(['standard', 'commercial'])],
            'assigned_officer_id' => ['nullable', Rule::exists('users', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'savings_account_id' => ['nullable', Rule::exists('accounts', 'id')->where('organization_id', $organizationId)],
            'application_date' => ['nullable', 'date', 'before_or_equal:today'],
            'application_fee_override' => [Rule::prohibitedIf(! $this->user()?->canPerform('disburse-loans')), 'nullable', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'application_fee_override_reason' => ['required_with:application_fee_override', 'nullable', 'string', 'max:1000'],
            'requested_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'term_periods' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'purpose' => ['nullable', 'string', 'max:255'],
            'source_of_repayment' => ['nullable', 'string', 'max:255'],
            'business_details' => ['nullable', 'string'],
            'existing_loans' => ['nullable', 'string'],
            'risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'credit_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'collateral_details' => ['nullable', 'string'],
            'credit_officer_comments' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $assignedOfficerId = $this->integer('assigned_officer_id');
                $canAssignOfficer = $this->user()?->canPerform('manage-users') || $this->user()?->canPerform('disburse-loans') || $this->user()?->hasRole('super-admin');

                if ($this->input('loan_type') === 'commercial' && ! $canAssignOfficer) {
                    $validator->errors()->add('loan_type', 'Only supervisors can create commercial loans.');

                    return;
                }

                $client = Client::query()->find($this->integer('client_id'));

                if ($client && $this->user()?->isLoanOfficer() && ! $this->loanOfficerCanUseClient($client, $this->user())) {
                    $validator->errors()->add('client_id', 'You can only create loans for clients in your branch.');
                }

                if ($client && $client->client_type === 'individual') {
                    if (blank($client->national_id)) {
                        $validator->errors()->add('client_id', 'The selected client must have a National ID / NIN before a loan can be created.');
                    }

                    if (blank($client->date_of_birth)) {
                        $validator->errors()->add('client_id', 'The selected client must have a date of birth before a loan can be created.');
                    }

                    if (blank($client->gender)) {
                        $validator->errors()->add('client_id', 'The selected client must have a gender before a loan can be created.');
                    }
                }

                if ($this->input('loan_type') === 'commercial' && ! $assignedOfficerId) {
                    $validator->errors()->add('assigned_officer_id', 'Commercial loans must be assigned to an organization admin, branch head, or Loan Officer.');

                    return;
                }

                if (! $assignedOfficerId) {
                    return;
                }

                if (! $canAssignOfficer) {
                    $validator->errors()->add('assigned_officer_id', 'Only supervisors can assign a loan to another Loan Officer.');

                    return;
                }

                $assignedOfficer = User::query()->with('role')->find($assignedOfficerId);

                if (! $assignedOfficer || ! in_array($assignedOfficer->role?->slug, RoleCatalog::LOAN_ASSIGNABLE_SLUGS, true)) {
                    $validator->errors()->add('assigned_officer_id', 'Select an active organization admin, branch head, or Loan Officer.');
                }
            },
        ];
    }

    private function loanOfficerCanUseClient(Client $client, User $user): bool
    {
        return $user->canAccessOrganization($client->organization_id)
            && ($user->branch_id === null || (int) $client->branch_id === (int) $user->branch_id);
    }
}
