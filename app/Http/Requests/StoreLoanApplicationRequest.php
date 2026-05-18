<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! $user->isTellerCashier()
            && ! $user->isMember()
            && $user->canPerform('manage-loans');
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
        return [
            'client_id' => ['required', Rule::exists('clients', 'id')->where('status', 'active')],
            'loan_product_id' => ['required', Rule::exists('loan_products', 'id')->where('status', 'active')],
            'loan_type' => ['required', Rule::in(['standard', 'commercial'])],
            'assigned_officer_id' => ['nullable', Rule::exists('users', 'id')->where('status', 'active')],
            'savings_account_id' => ['nullable', Rule::exists('accounts', 'id')],
            'application_date' => ['nullable', 'date', 'before_or_equal:today'],
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

                if ($this->input('loan_type') === 'commercial' && ! $assignedOfficerId) {
                    $validator->errors()->add('assigned_officer_id', 'Commercial loans must be assigned to a branch head or Loan Officer.');

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

                if (! $assignedOfficer || ! in_array($assignedOfficer->role?->slug, ['branch-manager', 'loan-officer'], true)) {
                    $validator->errors()->add('assigned_officer_id', 'Select an active branch head or Loan Officer.');
                }
            },
        ];
    }
}
