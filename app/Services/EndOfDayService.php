<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchCashReconciliation;
use App\Models\BranchDailySummary;
use App\Models\DailyAccountingSummary;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\FinancialDay;
use App\Models\JournalLine;
use App\Models\LoanDisbursement;
use App\Models\LoanPenalty;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\SavingsTransaction;
use App\Models\SmsLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EndOfDayService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private FinancialControlService $financialControls,
        private LoanService $loans,
        private SmsService $sms,
    ) {}

    public function getOrOpenFinancialDay(Branch $branch, CarbonInterface|string|null $businessDate = null, ?User $user = null): FinancialDay
    {
        $date = $this->date($businessDate);

        return FinancialDay::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'business_date' => $date->toDateString()],
            [
                'organization_id' => $branch->organization_id,
                'status' => 'open',
                'opened_by' => $user?->id,
                'opened_at' => now(),
            ],
        );
    }

    public function reconcile(Branch $branch, User $user, CarbonInterface|string|null $businessDate, float $countedCash, ?string $notes = null): BranchCashReconciliation
    {
        $reconciliation = $this->financialControls->reconcileBranchCash($branch, $user, $businessDate, $countedCash, $notes);
        $this->generateSummaries($branch, $businessDate, $user);

        return $reconciliation;
    }

    public function close(Branch $branch, User $user, CarbonInterface|string|null $businessDate, float $countedCash, ?string $notes = null): FinancialDay
    {
        $financialDay = $this->financialControls->closeFinancialDay($branch, $user, $businessDate, $countedCash, $notes);
        $this->process($branch, $businessDate, $user, true);

        return $financialDay->refresh();
    }

    public function lock(Branch $branch, User $user, CarbonInterface|string|null $businessDate): FinancialDay
    {
        $date = $this->date($businessDate);

        return DB::transaction(function () use ($branch, $date): FinancialDay {
            $financialDay = FinancialDay::query()
                ->where('branch_id', $branch->id)
                ->whereDate('business_date', $date->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            if ($financialDay->status === 'locked') {
                return $financialDay;
            }

            if ($financialDay->status !== 'closed') {
                throw new InvalidArgumentException('Only closed business days can be locked.');
            }

            $this->ensureNoOpenTellerSessions($branch);

            $financialDay->update(['status' => 'locked']);
            BranchDailySummary::query()
                ->where('branch_id', $branch->id)
                ->whereDate('business_date', $date->toDateString())
                ->update(['status' => 'locked']);

            $this->auditLogger->record('end_of_day.locked', $financialDay, [
                'branch_id' => $branch->id,
                'business_date' => $date->toDateString(),
            ]);

            return $financialDay->refresh();
        });
    }

    public function process(Branch $branch, CarbonInterface|string|null $businessDate = null, ?User $user = null, bool $sendReminders = true): BranchDailySummary
    {
        $date = $this->date($businessDate);

        $penaltyCount = $this->loans->applyOverduePenalties(null, $date, $branch->id);
        $remindersQueued = $sendReminders ? $this->queueRepaymentReminders($branch, $date, $user) : 0;
        $summary = $this->generateSummaries($branch, $date, $user, [
            'overdue_checked' => $penaltyCount,
            'reminders_queued' => $remindersQueued,
        ]);

        $this->auditLogger->record('end_of_day.processed', $summary, [
            'branch_id' => $branch->id,
            'business_date' => $date->toDateString(),
            'overdue_checked' => $penaltyCount,
            'reminders_queued' => $remindersQueued,
        ]);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function generateSummaries(Branch $branch, CarbonInterface|string|null $businessDate = null, ?User $user = null, array $metadata = []): BranchDailySummary
    {
        $date = $this->date($businessDate);

        return DB::transaction(function () use ($branch, $date, $user, $metadata): BranchDailySummary {
            $financialDay = $this->getOrOpenFinancialDay($branch, $date, $user);
            $reconciliation = BranchCashReconciliation::query()
                ->where('branch_id', $branch->id)
                ->whereDate('business_date', $date->toDateString())
                ->first();

            $drawerSessions = DrawerSession::query()
                ->whereHas('drawer', fn ($query) => $query->where('branch_id', $branch->id))
                ->whereDate('opened_at', $date->toDateString());
            $closedSessions = (clone $drawerSessions)->where('status', 'closed')->count();
            $openSessions = (clone $drawerSessions)->where('status', 'open')->count();
            $openingCash = (float) (clone $drawerSessions)->sum('opening_balance');

            $cashMovements = DrawerMovement::query()
                ->whereHas('session.drawer', fn ($query) => $query->where('branch_id', $branch->id))
                ->whereDate('created_at', $date->toDateString());
            $cashIn = (float) (clone $cashMovements)->where('type', 'cash_in')->sum('amount');
            $cashOut = (float) (clone $cashMovements)->where('type', 'cash_out')->sum('amount');

            $savingsTransactions = SavingsTransaction::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'successful')
                ->whereDate('transaction_date', $date->toDateString());
            $savingsDeposits = (float) (clone $savingsTransactions)->where('type', 'deposit')->sum('amount');
            $savingsWithdrawals = (float) (clone $savingsTransactions)->where('type', 'withdrawal')->sum('amount');

            $loanRepayments = LoanRepayment::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'successful')
                ->whereDate('paid_at', $date->toDateString());
            $loanDisbursements = LoanDisbursement::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'successful')
                ->whereDate('disbursement_date', $date->toDateString());

            $penaltiesApplied = (float) LoanPenalty::query()
                ->whereHas('loan', fn ($query) => $query
                    ->where('branch_id', $branch->id)
                    ->where('status', '!=', 'cancelled'))
                ->whereDate('calculated_for_date', $date->toDateString())
                ->sum('amount');

            $overdueInstallments = LoanSchedule::query()
                ->excludingCancelledLoans()
                ->eligibleForArrears($date)
                ->whereHas('loan', fn ($query) => $query->where('branch_id', $branch->id))
                ->count();

            $journalLines = JournalLine::query()
                ->whereHas('journalEntry', fn ($query) => $query
                    ->where('branch_id', $branch->id)
                    ->whereDate('journal_date', $date->toDateString()));
            $journalDebits = (float) (clone $journalLines)->sum('debit');
            $journalCredits = (float) (clone $journalLines)->sum('credit');

            $expectedCash = $reconciliation ? (float) $reconciliation->expected_cash : (float) $branch->drawers()->sum('current_balance');
            $countedCash = $reconciliation ? (float) $reconciliation->counted_cash : $expectedCash;

            $summary = BranchDailySummary::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'business_date' => $date->toDateString()],
                [
                    'organization_id' => $branch->organization_id,
                    'financial_day_id' => $financialDay->id,
                    'teller_sessions_opened' => (clone $drawerSessions)->count(),
                    'teller_sessions_closed' => $closedSessions,
                    'open_teller_sessions' => $openSessions,
                    'opening_cash' => $openingCash,
                    'expected_cash' => $expectedCash,
                    'counted_cash' => $countedCash,
                    'cash_variance' => $countedCash - $expectedCash,
                    'cash_transaction_count' => (clone $cashMovements)->count(),
                    'cash_in' => $cashIn,
                    'cash_out' => $cashOut,
                    'savings_transaction_count' => (clone $savingsTransactions)->count(),
                    'savings_deposits' => $savingsDeposits,
                    'savings_withdrawals' => $savingsWithdrawals,
                    'loan_repayment_count' => (clone $loanRepayments)->count(),
                    'loan_repayments' => (float) (clone $loanRepayments)->sum('amount'),
                    'loan_disbursement_count' => (clone $loanDisbursements)->count(),
                    'loan_disbursements' => (float) (clone $loanDisbursements)->sum('disbursed_amount'),
                    'overdue_installments' => $overdueInstallments,
                    'penalties_applied' => $penaltiesApplied,
                    'reminders_queued' => (int) ($metadata['reminders_queued'] ?? 0),
                    'journal_debits' => $journalDebits,
                    'journal_credits' => $journalCredits,
                    'status' => $financialDay->status,
                    'metadata' => $metadata,
                    'generated_by' => $user?->id,
                    'generated_at' => now(),
                ],
            );

            $this->generateAccountingSummaries($branch, $financialDay, $date, $user);

            return $summary->refresh();
        });
    }

    public function queueRepaymentReminders(Branch $branch, CarbonInterface|string|null $businessDate = null, ?User $user = null): int
    {
        $date = $this->date($businessDate);
        $reminderDate = $date->addDay();
        $count = 0;

        LoanSchedule::query()
            ->with(['loan.client'])
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', fn ($query) => $query->where('branch_id', $branch->id))
            ->where('outstanding_amount', '>', 0)
            ->where(fn ($query) => $query
                ->whereDate('due_date', $reminderDate->toDateString())
                ->orWhere('status', 'overdue'))
            ->orderBy('due_date')
            ->cursor()
            ->each(function (LoanSchedule $schedule) use ($user, &$count): void {
                $client = $schedule->loan?->client;

                if (! $client?->phone) {
                    return;
                }

                $existing = SmsLog::query()
                    ->where('type', 'repayment_reminder')
                    ->where('notifiable_type', $schedule->getMorphClass())
                    ->where('notifiable_id', $schedule->id)
                    ->whereDate('created_at', today()->toDateString())
                    ->exists();

                if ($existing) {
                    return;
                }

                $sms = $this->sms->queue(
                    $client->phone,
                    sprintf(
                        'Dear %s, your DigitoFina loan installment of UGX %s is due on %s. Please pay on time to avoid penalties.',
                        $client->full_name ?: 'member',
                        number_format((float) $schedule->outstanding_amount, 2),
                        $schedule->due_date->format('d M Y'),
                    ),
                    'repayment_reminder',
                    $user,
                );

                $sms->update([
                    'organization_id' => $schedule->loan->organization_id,
                    'notifiable_type' => $schedule->getMorphClass(),
                    'notifiable_id' => $schedule->id,
                    'metadata' => [
                        'loan_id' => $schedule->loan_id,
                        'due_date' => $schedule->due_date->toDateString(),
                        'outstanding_amount' => (float) $schedule->outstanding_amount,
                    ],
                ]);

                $count++;
            });

        return $count;
    }

    private function ensureNoOpenTellerSessions(Branch $branch): void
    {
        $openSessions = DrawerSession::query()
            ->whereHas('drawer', fn ($query) => $query->where('branch_id', $branch->id))
            ->where('status', 'open')
            ->exists();

        if ($openSessions) {
            throw new InvalidArgumentException('All teller sessions must be closed before locking the business day.');
        }
    }

    private function generateAccountingSummaries(Branch $branch, FinancialDay $financialDay, CarbonImmutable $date, ?User $user = null): void
    {
        DailyAccountingSummary::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $date->toDateString())
            ->delete();

        JournalLine::query()
            ->with('chartAccount')
            ->selectRaw('chart_account_id, SUM(debit) as debit_total, SUM(credit) as credit_total, COUNT(*) as line_count')
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('branch_id', $branch->id)
                ->whereDate('journal_date', $date->toDateString()))
            ->groupBy('chart_account_id')
            ->get()
            ->each(function (JournalLine $line) use ($branch, $financialDay, $date, $user): void {
                $chartAccount = $line->chartAccount;
                $debits = (float) $line->getAttribute('debit_total');
                $credits = (float) $line->getAttribute('credit_total');

                DailyAccountingSummary::create([
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'financial_day_id' => $financialDay->id,
                    'chart_account_id' => $chartAccount?->id,
                    'business_date' => $date->toDateString(),
                    'account_code' => $chartAccount?->code,
                    'account_name' => $chartAccount?->name ?? 'Unmapped account',
                    'debit_total' => $debits,
                    'credit_total' => $credits,
                    'net_movement' => $debits - $credits,
                    'line_count' => (int) $line->getAttribute('line_count'),
                    'generated_by' => $user?->id,
                    'generated_at' => now(),
                ]);
            });
    }

    private function date(CarbonInterface|string|null $businessDate): CarbonImmutable
    {
        return $businessDate instanceof CarbonInterface
            ? CarbonImmutable::parse($businessDate->toDateString())
            : CarbonImmutable::parse($businessDate ?: today()->toDateString());
    }
}
