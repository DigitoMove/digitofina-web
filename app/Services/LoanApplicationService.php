<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Validation\ValidationException;

class LoanApplicationService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, User $officer): Loan
    {
        $client = Client::with('branch')->findOrFail($data['client_id']);
        $product = LoanProduct::findOrFail($data['loan_product_id']);
        $savings = Account::where('client_id', $client->id)->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->first();
        $requestedAmount = (float) $data['requested_amount'];
        $assignedOfficer = $this->assignedOfficer($data, $officer);
        CurrencyDenomination::assertValid($requestedAmount, 'Requested amount');

        if ($requestedAmount < (float) $product->min_amount || $requestedAmount > (float) $product->max_amount) {
            throw ValidationException::withMessages(['requested_amount' => 'Requested amount is outside the selected product limits.']);
        }

        if ((int) $data['term_periods'] < (int) $product->min_term || (int) $data['term_periods'] > (int) $product->max_term) {
            throw ValidationException::withMessages(['term_periods' => 'Loan term is outside the selected product limits.']);
        }

        $loan = Loan::create(collect($data)->except('assigned_officer_id')->all() + [
            'organization_id' => $officer->organization_id,
            'branch_id' => $client->branch_id,
            'officer_id' => $assignedOfficer->id,
            'created_by' => $officer->id,
            'savings_account_id' => $savings?->id,
            'loan_number' => $this->numbers->loanNumber($client->branch),
            'interest_rate' => $product->annual_interest_rate,
            'savings_balance_at_application' => (float) ($savings?->balance ?? 0),
            'required_savings_amount' => round($requestedAmount * ((float) $product->required_savings_percentage / 100), 2),
            'application_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $this->auditLogger->record('loan.created', $loan, $loan->toArray());

        return $loan->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assignedOfficer(array $data, User $creator): User
    {
        $assignedOfficerId = (int) ($data['assigned_officer_id'] ?? 0);
        $isCommercial = ($data['loan_type'] ?? 'standard') === 'commercial';

        if ($assignedOfficerId === 0) {
            if ($isCommercial && ($creator->hasRole('super-admin') || $creator->canPerform('manage-users') || $creator->canPerform('disburse-loans'))) {
                throw ValidationException::withMessages(['assigned_officer_id' => 'Commercial loans must be assigned to a branch head or Loan Officer.']);
            }

            return $creator;
        }

        if (! ($creator->hasRole('super-admin') || $creator->canPerform('manage-users') || $creator->canPerform('disburse-loans'))) {
            throw ValidationException::withMessages(['assigned_officer_id' => 'Only supervisors can assign a loan to another Loan Officer.']);
        }

        $assignedOfficer = User::query()
            ->with('role')
            ->where('status', 'active')
            ->findOrFail($assignedOfficerId);

        if (! in_array($assignedOfficer->role?->slug, ['branch-manager', 'loan-officer'], true)) {
            throw ValidationException::withMessages(['assigned_officer_id' => 'Select an active branch head or Loan Officer.']);
        }

        return $assignedOfficer;
    }
}
