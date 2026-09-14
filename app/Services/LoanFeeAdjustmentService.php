<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\FinancialDay;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class LoanFeeAdjustmentService
{
    public function __construct(private TransactionPostingService $transactions, private AuditLogger $auditLogger) {}

    public function validateOverride(User $user, ?float $amount, string $reason): void
    {
        abort_unless($user->canPerform('disburse-loans'), 403);

        if (blank($reason) || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Provide a reason of at most 1,000 characters for the fee adjustment.');
        }

        if ($amount !== null && $amount !== 0.0) {
            CurrencyDenomination::assertValid($amount, 'Application fee');
        }
    }

    public function setOverride(Loan $loan, User $user, ?float $amount, string $reason): Loan
    {
        Gate::forUser($user)->authorize('view', $loan);
        $this->validateOverride($user, $amount, $reason);

        return DB::transaction(function () use ($loan, $user, $amount, $reason): Loan {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, ['draft', 'under_review', 'submitted', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved'], true) || $loan->disbursements()->exists()) {
                throw new InvalidArgumentException('Fees already charged cannot be changed. Use an application fee refund after disbursement.');
            }

            $old = $loan->only(['application_fee_override', 'application_fee_override_reason']);
            $loan->update(['application_fee_override' => $amount, 'application_fee_override_reason' => $reason]);
            $this->auditLogger->record('loan.application_fee_override_updated', $loan, $loan->only(['application_fee_override', 'application_fee_override_reason']), $old, actor: $user);

            return $loan;
        });
    }

    public function refund(Loan $loan, int $disbursementId, User $user, float $amount, string $reason): Transaction
    {
        Gate::forUser($user)->authorize('view', $loan);
        $this->validateOverride($user, $amount, $reason);
        CurrencyDenomination::assertValid($amount, 'Refund amount');

        return DB::transaction(function () use ($loan, $disbursementId, $user, $amount, $reason): Transaction {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $disbursement = $loan->disbursements()->whereKey($disbursementId)->lockForUpdate()->firstOrFail();

            if ($disbursement->status !== 'successful' || (float) $disbursement->fees_deducted <= 0) {
                throw new InvalidArgumentException('This disbursement has no application fee to refund.');
            }

            $disbursementTransaction = Transaction::query()
                ->where('reference', $disbursement->transaction_reference)
                ->where('organization_id', $loan->organization_id)
                ->lockForUpdate()->firstOrFail();

            $charge = Transaction::query()
                ->where('account_id', $disbursementTransaction->account_id)
                ->where('organization_id', $loan->organization_id)
                ->whereHas('type', fn ($query) => $query->where('code', 'loan_application_fee'))
                ->where('description', "Loan application fee paid from savings for loan {$loan->loan_number}")
                ->whereNull('reversal_of_id')
                ->lockForUpdate()->first() ?? $disbursementTransaction;

            if ($charge->status !== 'posted' || $charge->reversed_by !== null || $disbursementTransaction->status !== 'posted' || $disbursementTransaction->reversed_by !== null) {
                throw new InvalidArgumentException('The original fee or disbursement has been reversed.');
            }

            $refunded = (float) $disbursement->applicationFeeRefunds()
                ->where('status', 'posted')->whereNull('reversal_of_id')->whereNull('reversed_by')->sum('amount');
            $available = round((float) $disbursement->fees_deducted - $refunded, 2);

            if ($amount > $available) {
                throw new InvalidArgumentException('Refund cannot exceed the remaining application fee of UGX '.number_format($available, 2).'.');
            }

            $savings = Account::query()->with('accountType')->whereKey($charge->account_id)->lockForUpdate()->firstOrFail();

            if ((int) $savings->client_id !== (int) $loan->client_id || (int) $savings->organization_id !== (int) $loan->organization_id || $savings->status !== 'active' || $savings->accountType?->category !== 'savings') {
                throw new InvalidArgumentException('The original client savings account must be active to receive a refund.');
            }

            if (FinancialDay::query()->where('branch_id', $savings->branch_id)->whereDate('business_date', today())->whereIn('status', ['closed', 'locked'])->exists()) {
                throw new InvalidArgumentException('The financial day is closed and locked for this branch.');
            }

            $incomeEntry = $charge->journalEntries()->with('chartAccount')
                ->where('entry_type', 'credit')->where('status', 'posted')
                ->where('memo', 'like', '%application fee%')
                ->whereHas('chartAccount', fn ($query) => $query->where('type', 'income')->where('organization_id', $loan->organization_id))
                ->first();
            $savingsLedger = ChartAccount::query()->where('organization_id', $loan->organization_id)->where('code', '2100')->first();

            if (! $incomeEntry || ! $savingsLedger || (float) $incomeEntry->amount < (float) $disbursement->fees_deducted) {
                throw new InvalidArgumentException('The original application fee ledger entries must be reconciled before refunding.');
            }

            $type = TransactionType::query()->firstOrCreate(['code' => 'loan_application_fee_refund'], ['name' => 'Loan Application Fee Refund', 'direction' => 'credit']);
            $refund = $this->transactions->postNonCashCredit($savings, $type, $amount, $user,
                "Application fee refund for loan {$loan->loan_number}: {$reason}", [
                    ['account_code' => $incomeEntry->chartAccount->code, 'entry_type' => 'debit', 'amount' => $amount, 'memo' => 'Application fee refund'],
                    ['account_code' => $savingsLedger->code, 'entry_type' => 'credit', 'amount' => $amount, 'memo' => 'Application fee refund credited to client savings'],
                ], now());
            $refund->update(['application_fee_disbursement_id' => $disbursement->id, 'fee_refund_of_id' => $charge->id]);
            $this->auditLogger->record('loan.application_fee_refunded', $loan, [
                'disbursement_id' => $disbursement->id,
                'original_transaction_id' => $charge->id,
                'refund_transaction_id' => $refund->id,
                'refund_reference' => $refund->reference,
                'amount' => $amount,
                'reason' => $reason,
                'remaining_refundable' => round($available - $amount, 2),
            ], actor: $user);

            return $refund;
        });
    }
}
