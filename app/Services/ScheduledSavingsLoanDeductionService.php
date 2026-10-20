<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanSavingsDeductionRun;
use App\Models\LoanSavingsDeductionSchedule;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ScheduledSavingsLoanDeductionService
{
    public function __construct(
        private FinancialRelationshipService $relationships,
        private AuditLogger $auditLogger,
    ) {}

    public function create(
        Account|SavingsAccount $savingsAccount,
        Loan $loan,
        float $amount,
        string $frequency,
        CarbonInterface $nextRunAt,
        User $user,
    ): LoanSavingsDeductionSchedule {
        CurrencyDenomination::assertValid($amount);

        if (! in_array($frequency, LoanSavingsDeductionSchedule::FREQUENCIES, true)) {
            throw new InvalidArgumentException('Choose a supported deduction frequency.');
        }

        ApiSecurity::assertTenantAccess($user, $savingsAccount);
        ApiSecurity::assertTenantAccess($user, $loan);

        if ((int) $savingsAccount->organization_id !== (int) $loan->organization_id
            || (int) $savingsAccount->branch_id !== (int) $loan->branch_id
            || (int) $savingsAccount->client_id !== (int) $loan->client_id) {
            throw new InvalidArgumentException('The savings account and loan must belong to the same client, branch, and organization.');
        }

        if ($savingsAccount->status !== 'active') {
            throw new InvalidArgumentException('Savings account must be active.');
        }

        if ($savingsAccount instanceof Account && $savingsAccount->accountType?->category !== 'savings') {
            throw new InvalidArgumentException('The source account must be a savings account.');
        }

        if (! in_array($loan->status, Loan::OPERATIONAL_STATUSES, true)) {
            throw new InvalidArgumentException('Loan must be active or in arrears.');
        }

        $sourceColumn = $savingsAccount instanceof Account ? 'account_id' : 'savings_account_id';

        if (LoanSavingsDeductionSchedule::query()
            ->where('loan_id', $loan->id)
            ->where($sourceColumn, $savingsAccount->id)
            ->whereIn('status', ['draft', 'active'])
            ->exists()) {
            throw new InvalidArgumentException('This savings-to-loan deduction already has an active or pending schedule.');
        }

        $schedule = LoanSavingsDeductionSchedule::create([
            'organization_id' => $loan->organization_id,
            'branch_id' => $loan->branch_id,
            'client_id' => $loan->client_id,
            'loan_id' => $loan->id,
            'savings_account_id' => $savingsAccount instanceof SavingsAccount ? $savingsAccount->id : null,
            'account_id' => $savingsAccount instanceof Account ? $savingsAccount->id : null,
            'created_by' => $user->id,
            'amount' => $amount,
            'frequency' => $frequency,
            'next_run_at' => CarbonImmutable::instance($nextRunAt),
            'status' => 'draft',
        ]);

        $this->auditLogger->record('scheduled_savings_loan_deduction.created', $schedule, [
            'amount' => $amount,
            'frequency' => $frequency,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
        ], actor: $user);

        return $schedule;
    }

    public function activate(LoanSavingsDeductionSchedule $schedule, User $user): LoanSavingsDeductionSchedule
    {
        $this->assertAdministrator($user);
        ApiSecurity::assertTenantAccess($user, $schedule);

        if (! in_array($schedule->status, ['draft', 'disabled'], true)) {
            throw new InvalidArgumentException('Only draft or disabled schedules can be activated.');
        }

        $schedule->update([
            'status' => 'active',
            'activated_by' => $user->id,
            'activated_at' => now(),
            'disabled_by' => null,
            'disabled_at' => null,
            'last_result' => null,
            'last_message' => null,
        ]);
        $this->auditLogger->record('scheduled_savings_loan_deduction.activated', $schedule, actor: $user);

        return $schedule->refresh();
    }

    public function disable(LoanSavingsDeductionSchedule $schedule, User $user, string $status = 'disabled'): LoanSavingsDeductionSchedule
    {
        $this->assertAdministrator($user);
        ApiSecurity::assertTenantAccess($user, $schedule);

        if (! in_array($status, ['disabled', 'cancelled'], true)) {
            throw new InvalidArgumentException('Unsupported schedule status.');
        }

        if ($schedule->status === 'cancelled') {
            throw new InvalidArgumentException('Cancelled schedules cannot be changed.');
        }

        $schedule->update([
            'status' => $status,
            'disabled_by' => $user->id,
            'disabled_at' => now(),
        ]);
        $this->auditLogger->record("scheduled_savings_loan_deduction.{$status}", $schedule, actor: $user);

        return $schedule->refresh();
    }

    /**
     * @return array{checked:int,succeeded:int,skipped:int,failed:int}
     */
    public function processDueSchedules(CarbonInterface|string|null $now = null, ?int $organizationId = null, ?int $branchId = null): array
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();
        $summary = ['checked' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 0];

        $ids = LoanSavingsDeductionSchedule::query()
            ->where('status', 'active')
            ->where('next_run_at', '<=', $now)
            ->when($organizationId, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $scheduleId) {
            $result = $this->processSchedule((int) $scheduleId, $now);
            $summary['checked']++;
            $summary[$result]++;
        }

        return $summary;
    }

    private function processSchedule(int $scheduleId, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($scheduleId, $now): string {
            $schedule = LoanSavingsDeductionSchedule::query()->whereKey($scheduleId)->lockForUpdate()->first();

            if (! $schedule || $schedule->status !== 'active' || ! $schedule->next_run_at || $schedule->next_run_at->gt($now)) {
                return 'skipped';
            }

            $dueAt = CarbonImmutable::instance($schedule->next_run_at);
            $idempotencyKey = "scheduled-savings-loan:{$schedule->id}:{$dueAt->toIso8601String()}";
            $run = LoanSavingsDeductionRun::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($run && $run->status !== 'processing') {
                return 'skipped';
            }

            $run ??= LoanSavingsDeductionRun::create([
                'organization_id' => $schedule->organization_id,
                'branch_id' => $schedule->branch_id,
                'schedule_id' => $schedule->id,
                'amount' => $schedule->amount,
                'due_at' => $dueAt,
                'idempotency_key' => $idempotencyKey,
                'status' => 'processing',
            ]);

            try {
                $actor = $this->processingActor($schedule);

                if (! $actor) {
                    throw new InvalidArgumentException('No active staff member with savings and repayment posting permission is available.');
                }

                $loan = Loan::query()->whereKey($schedule->loan_id)->firstOrFail();
                $savingsAccount = $schedule->account_id
                    ? Account::query()->with('accountType')->whereKey($schedule->account_id)->firstOrFail()
                    : SavingsAccount::query()->whereKey($schedule->savings_account_id)->firstOrFail();
                $this->assertCurrentRelationship($schedule, $savingsAccount, $loan);
                CurrencyDenomination::assertValid((float) $schedule->amount);
                $repayment = $savingsAccount instanceof Account
                    ? $this->relationships->repayLoanFromAccountSavings($loan, $savingsAccount, (float) $schedule->amount, $actor, true)
                    : $this->relationships->repayLoanFromSavings($loan, $savingsAccount, (float) $schedule->amount, $actor, true);
                $savingsTransaction = $savingsAccount instanceof SavingsAccount
                    ? SavingsTransaction::query()
                        ->where('savings_account_id', $savingsAccount->id)
                        ->where('reference', $repayment->reference)
                        ->latest('id')
                        ->first()
                    : null;

                $run->update([
                    'status' => 'succeeded',
                    'savings_transaction_id' => $savingsTransaction?->id,
                    'loan_repayment_id' => $repayment->id,
                    'processed_at' => now(),
                ]);
                $loanFullyCleared = $loan->fresh()->status === 'closed';
                $this->finishSchedule(
                    $schedule,
                    $now,
                    'succeeded',
                    $loanFullyCleared ? 'Scheduled savings repayment posted and the loan is fully cleared. Schedule stopped.' : 'Scheduled savings repayment posted successfully.',
                    $loanFullyCleared,
                );

                return 'succeeded';
            } catch (Throwable $exception) {
                $result = $this->isInsufficientFunds($exception) ? 'skipped' : 'failed';
                $message = $exception->getMessage() ?: 'Scheduled deduction could not be posted.';
                $run->update(['status' => $result, 'failure_reason' => $message, 'processed_at' => now()]);
                $this->finishSchedule($schedule, $now, $result, $message);

                return $result;
            }
        });
    }

    private function finishSchedule(LoanSavingsDeductionSchedule $schedule, CarbonImmutable $now, string $result, string $message, bool $stopSchedule = false): void
    {
        $nextRunAt = CarbonImmutable::instance($schedule->next_run_at);

        do {
            $nextRunAt = match ($schedule->frequency) {
                'daily' => $nextRunAt->addDay(),
                'weekly' => $nextRunAt->addWeek(),
                'biweekly' => $nextRunAt->addWeeks(2),
                'monthly' => $nextRunAt->addMonthNoOverflow(),
            };
        } while ($nextRunAt->lte($now));

        $schedule->update([
            'next_run_at' => $nextRunAt,
            'last_run_at' => now(),
            'last_result' => $result,
            'last_message' => $message,
            'status' => $stopSchedule ? 'disabled' : $schedule->status,
            'disabled_at' => $stopSchedule ? now() : $schedule->disabled_at,
        ]);
    }

    private function processingActor(LoanSavingsDeductionSchedule $schedule): ?User
    {
        return User::query()
            ->where('status', 'active')
            ->where('is_active', true)
            ->where(function (Builder $query) use ($schedule): void {
                $query->where('organization_id', $schedule->organization_id)
                    ->orWhereHas('role', fn (Builder $query): Builder => $query->where('slug', 'super-admin'));
            })
            ->get()
            ->first(fn (User $user): bool => $user->canAccessBranch($schedule->branch_id, $schedule->organization_id)
                && $user->canPerform('post-transactions')
                && $user->canPerform('post-repayments'));
    }

    private function assertAdministrator(User $user): void
    {
        if (! $user->hasAnyRole(['super-admin', 'organization-admin'])) {
            throw new InvalidArgumentException('Only administrators can activate, disable, or cancel scheduled deductions.');
        }
    }

    private function assertCurrentRelationship(LoanSavingsDeductionSchedule $schedule, Account|SavingsAccount $savingsAccount, Loan $loan): void
    {
        if ((int) $schedule->organization_id !== (int) $loan->organization_id
            || (int) $schedule->organization_id !== (int) $savingsAccount->organization_id
            || (int) $schedule->branch_id !== (int) $loan->branch_id
            || (int) $schedule->branch_id !== (int) $savingsAccount->branch_id
            || (int) $schedule->client_id !== (int) $loan->client_id
            || (int) $schedule->client_id !== (int) $savingsAccount->client_id) {
            throw new InvalidArgumentException('The scheduled savings account and loan no longer belong to the same client, branch, and organization.');
        }
    }

    private function isInsufficientFunds(Throwable $exception): bool
    {
        return str($exception->getMessage())->contains(['unlocked savings balance', 'available savings balance', 'minimum balance']);
    }
}
