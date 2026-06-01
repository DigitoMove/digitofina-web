<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class LoanApplicationService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
        private LoanScheduleService $schedules,
        private InAppNotificationService $notifications,
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
        $applicationDate = CarbonImmutable::parse($data['application_date'] ?? today()->toDateString())->toDateString();
        $assignedOfficer = $this->assignedOfficer($data, $officer);
        CurrencyDenomination::assertValid($requestedAmount, 'Requested amount');
        $this->ensureClientBioComplete($client);

        if ($requestedAmount < (float) $product->min_amount || $requestedAmount > (float) $product->max_amount) {
            throw ValidationException::withMessages(['requested_amount' => 'Requested amount is outside the selected product limits.']);
        }

        if ((int) $data['term_periods'] < (int) $product->min_term || (int) $data['term_periods'] > (int) $product->max_term) {
            throw ValidationException::withMessages(['term_periods' => 'Loan term is outside the selected product limits.']);
        }

        $loan = Loan::create(collect($data)->except('assigned_officer_id', 'application_date', 'savings_account_id')->all() + [
            'organization_id' => $officer->organization_id,
            'branch_id' => $client->branch_id,
            'officer_id' => $assignedOfficer->id,
            'created_by' => $officer->id,
            'savings_account_id' => $savings?->id,
            'loan_number' => $this->numbers->loanNumber($client->branch),
            'interest_rate' => $product->annual_interest_rate,
            'savings_balance_at_application' => (float) ($savings?->balance ?? 0),
            'required_savings_amount' => round($requestedAmount * ((float) $product->required_savings_percentage / 100), 2),
            'application_date' => $applicationDate,
            'status' => 'draft',
        ]);

        $this->writePreviewSchedule($loan->refresh()->load('product'), $requestedAmount);
        $copiedGuarantors = $this->copyRecentGuarantors($loan);
        $this->auditLogger->record('loan.created', $loan, $loan->toArray());
        if ($copiedGuarantors > 0) {
            $this->auditLogger->record('loan.guarantors_reused', $loan, [
                'copied_guarantors' => $copiedGuarantors,
            ]);
        }
        $freshLoan = $loan->refresh()->loadMissing('client', 'officer');
        $loanUrl = route('loans.show', $freshLoan);
        $this->notifications->notifyLoanOfficer(
            $freshLoan,
            'Loan application created',
            "Loan {$freshLoan->loan_number} for {$freshLoan->client?->full_name} has been created.",
            $loanUrl,
            'loan',
            'info',
        );
        $this->notifications->notifyLoanClient(
            $freshLoan,
            'Loan application created',
            'A loan application has been opened on your account.',
            $loanUrl,
            'loan',
            'info',
        );

        return $freshLoan;
    }

    private function copyRecentGuarantors(Loan $loan): int
    {
        $sourceLoan = Loan::query()
            ->with(['loanGuarantors' => fn ($query) => $query
                ->whereNotIn('status', ['rejected', 'released'])
                ->oldest('id')])
            ->where('client_id', $loan->client_id)
            ->whereKeyNot($loan->id)
            ->whereHas('loanGuarantors', fn ($query) => $query->whereNotIn('status', ['rejected', 'released']))
            ->orderByDesc('application_date')
            ->orderByDesc('id')
            ->first();

        if (! $sourceLoan) {
            return 0;
        }

        foreach ($sourceLoan->loanGuarantors as $guarantor) {
            $loan->loanGuarantors()->create([
                'organization_id' => $loan->organization_id,
                'guarantor_client_id' => $guarantor->guarantor_client_id,
                'type' => $guarantor->type,
                'name' => $guarantor->name,
                'phone' => $guarantor->phone,
                'national_id' => $guarantor->national_id,
                'address' => $guarantor->address,
                'relationship' => $guarantor->relationship,
                'guaranteed_amount' => $this->guaranteedAmount($loan),
                'document_path' => $guarantor->document_path,
                'status' => $guarantor->status,
            ]);
        }

        return $sourceLoan->loanGuarantors->count();
    }

    private function guaranteedAmount(Loan $loan): float
    {
        return (float) $loan->approved_amount > 0 ? (float) $loan->approved_amount : (float) $loan->requested_amount;
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

    private function ensureClientBioComplete(Client $client): void
    {
        if ($client->client_type !== 'individual') {
            return;
        }

        if (blank($client->national_id)) {
            throw ValidationException::withMessages(['client_id' => 'The selected client must have a National ID / NIN before a loan can be created.']);
        }

        if (blank($client->date_of_birth)) {
            throw ValidationException::withMessages(['client_id' => 'The selected client must have a date of birth before a loan can be created.']);
        }

        if (blank($client->gender)) {
            throw ValidationException::withMessages(['client_id' => 'The selected client must have a gender before a loan can be created.']);
        }
    }

    private function writePreviewSchedule(Loan $loan, float $principal): void
    {
        $rows = $this->schedules->preview(
            $principal,
            $this->periodicRate((float) $loan->interest_rate, $loan->repayment_frequency),
            max(1, (int) $loan->term_periods),
            $loan->product?->interest_method ?: 'reducing_balance_equal_installments',
            CarbonImmutable::parse($loan->application_date?->toDateString() ?? today()->toDateString()),
            $loan->repayment_frequency,
        );

        $loan->schedules()->delete();

        foreach ($rows as $row) {
            LoanSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $row['installment'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => $row['total'],
                'outstanding_amount' => $row['total'],
                'balance_due' => $row['total'],
                'status' => 'preview',
            ]);
        }

        $loan->update(['interest_balance' => round(collect($rows)->sum('interest'), 2)]);
    }

    private function periodicRate(float $annualRate, string $frequency): float
    {
        return match ($frequency) {
            'daily' => $annualRate / 365,
            'weekly' => $annualRate / 52,
            'bi-weekly' => $annualRate / 26,
            'quarterly' => $annualRate / 4,
            default => $annualRate / 12,
        };
    }
}
