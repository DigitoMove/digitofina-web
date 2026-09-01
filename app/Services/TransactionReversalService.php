<?php

namespace App\Services;

use App\Models\Account;
use App\Models\DrawerMovement;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\MakerCheckerRequest;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TransactionReversalService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private BusinessCalendarService $calendar,
        private LedgerReversalRepairService $ledgerReversalRepair,
    ) {}

    public function approve(MakerCheckerRequest $request, User $checker): Transaction
    {
        return DB::transaction(function () use ($request, $checker): Transaction {
            $original = Transaction::query()
                ->with(['account.accountType', 'type', 'drawerSession.drawer', 'originalTransaction'])
                ->whereKey($request->subject_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($original->status !== 'posted') {
                throw new InvalidArgumentException('Only posted transactions can be reversed.');
            }

            if ($original->reversed_by !== null || $original->reversed_at !== null) {
                throw new InvalidArgumentException('This transaction has already been reversed.');
            }

            $account = $original->account_id
                ? Account::query()->with('accountType')->whereKey($original->account_id)->lockForUpdate()->first()
                : null;

            $loanRepayment = LoanRepayment::query()
                ->with(['allocations.schedule', 'loan'])
                ->where('transaction_id', $original->id)
                ->lockForUpdate()
                ->first();

            if ($loanRepayment) {
                $this->reverseLoanRepayment($loanRepayment, $checker, $request->reason);
                $account?->refresh();
            } elseif ($account) {
                $this->reverseAccountBalance($original, $account);
            }

            $this->reverseDrawerMovement($original, $checker);

            $reversal = Transaction::create([
                'organization_id' => $original->organization_id,
                'account_id' => $original->account_id,
                'transaction_type_id' => $original->transaction_type_id,
                'drawer_session_id' => $original->drawer_session_id,
                'branch_id' => $original->branch_id,
                'user_id' => $checker->id,
                'reversal_of_id' => $original->id,
                'reference' => $this->reference(),
                'cheque_number' => $original->cheque_number,
                'amount' => $original->amount,
                'charges' => $original->charges,
                'charge_mode' => $original->charge_mode ?? LedgerChargeService::MODE_DEDUCT_FROM_AMOUNT,
                'balance_after' => $account?->fresh()?->balance,
                'currency' => $original->currency,
                'description' => 'Reversal of '.$original->reference.($request->reason ? ': '.$request->reason : ''),
                'status' => 'posted',
                'posted_at' => now(),
            ]);

            $this->reverseJournalEntries($original, $reversal, $checker, $request->reason);

            $original->update([
                'status' => 'reversed',
                'reversed_by' => $reversal->id,
                'reversed_at' => now(),
            ]);

            if ($loanRepayment) {
                $loanRepayment->update(['reversal_transaction_id' => $reversal->id]);
            }

            $this->auditLogger->record('transaction.reversed', $original, [
                'reversal_reference' => $reversal->reference,
                'approved_by' => $checker->id,
                'reason' => $request->reason,
            ], actor: $checker);

            return $reversal->refresh();
        });
    }

    private function reverseAccountBalance(Transaction $transaction, Account $account): void
    {
        $amount = (float) $transaction->amount;

        if ($this->isAccountDebit($transaction)) {
            $account->increment('balance', $amount);

            return;
        }

        if ((float) $account->balance < $amount) {
            throw new InvalidArgumentException('The account balance is too low to reverse this transaction.');
        }

        $account->decrement('balance', $amount);
    }

    private function reverseDrawerMovement(Transaction $transaction, User $checker): void
    {
        $movements = DrawerMovement::query()
            ->with('session.drawer')
            ->where('reference', $this->drawerMovementReference($transaction))
            ->orderBy('drawer_session_id')
            ->lockForUpdate()
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $drawerIds = $movements->map(fn (DrawerMovement $movement): ?int => $movement->session?->teller_drawer_id)->filter()->unique()->sort()->values();
        $drawers = TellerDrawer::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $drawerIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($movements->where('type', 'cash_in')->groupBy(fn (DrawerMovement $movement): ?int => $movement->session?->teller_drawer_id) as $drawerId => $cashInMovements) {
            $drawer = $drawers->get($drawerId);
            $requiredCash = (float) $cashInMovements->sum('amount');

            if (! $drawer || (float) $drawer->current_balance < $requiredCash) {
                throw new InvalidArgumentException('A destination drawer does not have enough cash to reverse this transaction.');
            }
        }

        foreach ($movements as $movement) {
            $drawer = $drawers->get($movement->session?->teller_drawer_id);

            if (! $drawer) {
                throw new InvalidArgumentException('A drawer linked to this transaction no longer exists.');
            }

            $amount = (float) $movement->amount;
            $reversalType = $movement->type === 'cash_in' ? 'cash_out' : 'cash_in';
            $reversalType === 'cash_out'
                ? $drawer->decrement('current_balance', $amount)
                : $drawer->increment('current_balance', $amount);
            $drawer->refresh();

            DrawerMovement::create([
                'organization_id' => $transaction->organization_id,
                'drawer_session_id' => $movement->drawer_session_id,
                'user_id' => $checker->id,
                'type' => $reversalType,
                'amount' => $amount,
                'balance_after' => $drawer->current_balance,
                'reference' => 'RV-'.$transaction->reference,
                'description' => 'Reversal of '.$transaction->reference,
            ]);
        }
    }

    private function drawerMovementReference(Transaction $transaction): string
    {
        if ($transaction->originalTransaction) {
            return 'RV-'.$transaction->originalTransaction->reference;
        }

        return $transaction->reference;
    }

    private function reverseLoanRepayment(LoanRepayment $repayment, User $checker, ?string $reason): void
    {
        if ($repayment->status === 'reversed') {
            throw new InvalidArgumentException('This loan repayment has already been reversed.');
        }

        $loan = Loan::query()->whereKey($repayment->loan_id)->lockForUpdate()->firstOrFail();

        $loan->increment('penalty_balance', (float) $repayment->penalty_paid);
        $loan->increment('fees_balance', (float) $repayment->fees_paid);
        $loan->increment('interest_balance', (float) $repayment->interest_paid);
        $loan->increment('principal_balance', (float) $repayment->principal_paid);

        if ($repayment->allocations->isNotEmpty()) {
            foreach ($repayment->allocations as $allocation) {
                $this->reverseSchedulePayment($allocation->schedule, $checker, [
                    'penalty' => (float) $allocation->penalty_paid,
                    'fees' => (float) $allocation->fees_paid,
                    'interest' => (float) $allocation->interest_paid,
                    'principal' => (float) $allocation->principal_paid,
                ]);

                $allocation->update([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                    'reversed_by' => $checker->id,
                ]);
            }
        } else {
            $this->reverseSchedulesWithoutAllocation($loan, $repayment, $checker);
        }

        $loan->refresh();
        $loan->update(['status' => $this->loanStatusAfterReversal($loan)]);

        $repayment->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by' => $checker->id,
            'reversal_reason' => $reason,
        ]);

        if ($loan->loan_account_id) {
            $loanAccountBalance = (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance;

            Account::query()
                ->whereKey($loan->loan_account_id)
                ->lockForUpdate()
                ->update([
                    'balance' => $loanAccountBalance,
                    'status' => $loanAccountBalance > 0 ? 'active' : 'closed',
                ]);
        }

        $this->auditLogger->record('loan.repayment_reversed', $repayment, [
            'loan_id' => $loan->id,
            'amount' => $repayment->amount,
            'approved_by' => $checker->id,
        ], actor: $checker);
    }

    /**
     * @param  array{penalty:float, fees:float, interest:float, principal:float}  $amounts
     */
    private function reverseSchedulePayment(?LoanSchedule $schedule, User $checker, array $amounts): void
    {
        if (! $schedule) {
            return;
        }

        $total = array_sum($amounts);
        $schedule->update([
            'principal_paid' => max(0, (float) $schedule->principal_paid - $amounts['principal']),
            'interest_paid' => max(0, (float) $schedule->interest_paid - $amounts['interest']),
            'fees_paid' => max(0, (float) $schedule->fees_paid - $amounts['fees']),
            'penalty_paid' => max(0, (float) $schedule->penalty_paid - $amounts['penalty']),
            'total_paid' => max(0, (float) $schedule->total_paid - $total),
            'amount_paid' => max(0, (float) $schedule->amount_paid - $total),
            'outstanding_amount' => (float) $schedule->outstanding_amount + $total,
            'balance_due' => (float) $schedule->balance_due + $total,
            'last_reversal_at' => now(),
            'last_reversal_by' => $checker->id,
        ]);

        $this->refreshScheduleStatus($schedule->refresh());
    }

    private function reverseSchedulesWithoutAllocation(Loan $loan, LoanRepayment $repayment, User $checker): void
    {
        $remaining = [
            'penalty' => (float) $repayment->penalty_paid,
            'fees' => (float) $repayment->fees_paid,
            'interest' => (float) $repayment->interest_paid,
            'principal' => (float) $repayment->principal_paid,
        ];

        foreach ($loan->schedules()->where('total_paid', '>', 0)->orderByDesc('due_date')->lockForUpdate()->get() as $schedule) {
            if (array_sum($remaining) <= 0) {
                break;
            }

            $amounts = [
                'penalty' => min($remaining['penalty'], (float) $schedule->penalty_paid),
                'fees' => min($remaining['fees'], (float) $schedule->fees_paid),
                'interest' => min($remaining['interest'], (float) $schedule->interest_paid),
                'principal' => min($remaining['principal'], (float) $schedule->principal_paid),
            ];

            foreach ($amounts as $bucket => $amount) {
                $remaining[$bucket] -= $amount;
            }

            $this->reverseSchedulePayment($schedule, $checker, $amounts);
        }
    }

    private function refreshScheduleStatus(LoanSchedule $schedule): void
    {
        $outstanding = (float) $schedule->outstanding_amount;
        $totalPaid = (float) $schedule->total_paid;

        $status = match (true) {
            $outstanding <= 0 => 'paid',
            $schedule->due_date->lt(today()) => 'overdue',
            $totalPaid > 0 => 'partially_paid',
            default => 'pending',
        };

        $schedule->update([
            'status' => $status,
            'days_overdue' => $status === 'overdue' ? $this->calendar->businessDaysBetween($schedule->due_date, today()) : 0,
        ]);
    }

    private function loanStatusAfterReversal(Loan $loan): string
    {
        $balance = (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance;

        if ($balance <= 0) {
            return 'closed';
        }

        return $loan->schedules()
            ->eligibleForArrears(today())
            ->exists() ? 'in_arrears' : 'active';
    }

    private function reverseJournalEntries(Transaction $original, Transaction $reversal, User $checker, ?string $reason): void
    {
        foreach (JournalEntry::query()->where('transaction_id', $original->id)->get() as $journal) {
            $payload = [
                'organization_id' => $journal->organization_id,
                'branch_id' => $journal->branch_id ?: $original->branch_id,
                'created_by' => $checker->id,
                'posted_by' => $checker->id,
                'journal_number' => 'RJ'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => today()->toDateString(),
                'description' => 'Reversal of '.$journal->reference,
                'status' => 'posted',
                'posted_at' => now(),
                'chart_account_id' => $journal->chart_account_id,
                'transaction_id' => $reversal->id,
                'reference' => $reversal->reference,
                'account_name' => $journal->account_name,
                'entry_type' => $journal->entry_type === 'debit' ? 'credit' : 'debit',
                'amount' => $journal->amount,
                'memo' => $reason ?: 'Transaction reversal',
            ];

            if (Schema::hasColumn('journal_entries', 'reversed_entry_id')) {
                $payload['reversed_entry_id'] = $journal->id;
            }

            $reversalJournal = JournalEntry::create($payload);
            $this->ledgerReversalRepair->repairJournal($journal, $reversal, $checker);

            if (Schema::hasColumn('journal_entries', 'reversed_entry_id')) {
                $journal->update(['reversed_entry_id' => $reversalJournal->id]);
            }
        }
    }

    private function isAccountDebit(Transaction $transaction): bool
    {
        return in_array($transaction->type?->direction, ['debit', 'withdrawal'], true);
    }

    private function reference(): string
    {
        return 'RV'.now()->format('ymdHis').Str::upper(Str::random(5));
    }
}
