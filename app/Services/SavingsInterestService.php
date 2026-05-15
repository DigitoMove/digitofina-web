<?php

namespace App\Services;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavingsInterestService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly JournalEntryService $journalEntryService,
    ) {}

    public function postDueInterest(?int $organizationId = null, ?int $branchId = null, ?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf ?: now();
        $posted = 0;

        SavingsAccount::query()
            ->with('product')
            ->where('status', 'active')
            ->when($organizationId, fn ($query, int $organizationId) => $query->where('organization_id', $organizationId))
            ->when($branchId, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->whereHas('product', fn ($query) => $query
                ->where('status', 'active')
                ->where('interest_rate', '>', 0))
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($asOf, &$posted): void {
                foreach ($accounts as $account) {
                    if ($this->postForAccount($account, $asOf)) {
                        $posted++;
                    }
                }
            });

        return $posted;
    }

    public function postForAccount(SavingsAccount $account, CarbonInterface $asOf): ?SavingsTransaction
    {
        return DB::transaction(function () use ($account, $asOf): ?SavingsTransaction {
            $account = SavingsAccount::query()
                ->with('product')
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isDue($account, $asOf)) {
                return null;
            }

            $balanceBefore = (float) $account->current_balance;
            $interest = $this->interestAmount($account, $asOf);

            if ($interest <= 0) {
                return null;
            }

            $balanceAfter = round($balanceBefore + $interest, 2);
            $periodKey = $this->periodKey($account->product->interest_posting_frequency, $asOf);

            $account->update([
                'current_balance' => $balanceAfter,
                'available_balance' => $balanceAfter,
            ]);

            $transaction = SavingsTransaction::create([
                'organization_id' => $account->organization_id,
                'branch_id' => $account->branch_id,
                'client_id' => $account->client_id,
                'savings_account_id' => $account->id,
                'reference' => 'INT'.now()->format('ymdHis').Str::upper(Str::random(6)),
                'type' => 'interest_posting',
                'amount' => $interest,
                'charges' => 0,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'payment_method' => 'system',
                'narration' => 'Savings interest posting for '.$periodKey,
                'transaction_date' => $asOf->toDateString(),
                'value_date' => $asOf->toDateString(),
                'status' => 'successful',
            ]);

            $this->journalEntryService->post([
                ['account_code' => '5000', 'debit' => $interest, 'credit' => 0, 'narration' => 'Savings interest expense'],
                ['account_code' => '2100', 'debit' => 0, 'credit' => $interest, 'narration' => 'Savings interest credited to member'],
            ], 'Savings interest posting '.$account->account_number.' '.$periodKey, branchId: $account->branch_id);

            $this->auditLogger->record('savings.interest_posted', $transaction, [
                'account_number' => $account->account_number,
                'amount' => $interest,
                'frequency' => $account->product->interest_posting_frequency,
                'period_key' => $periodKey,
            ]);

            return $transaction;
        });
    }

    private function isDue(SavingsAccount $account, CarbonInterface $asOf): bool
    {
        $frequency = $account->product->interest_posting_frequency ?: 'monthly';
        $periodStart = $this->periodStart($frequency, $asOf)->toDateString();
        $periodEnd = $this->periodEnd($frequency, $asOf)->toDateString();

        if (! $this->isPostingDay($frequency, $asOf)) {
            return false;
        }

        return ! $account->transactions()
            ->where('type', 'interest_posting')
            ->whereDate('value_date', '>=', $periodStart)
            ->whereDate('value_date', '<=', $periodEnd)
            ->where('status', 'successful')
            ->exists();
    }

    private function isPostingDay(string $frequency, CarbonInterface $asOf): bool
    {
        return match ($frequency) {
            'daily' => true,
            'weekly' => $asOf->isSameDay($asOf->copy()->endOfWeek()),
            'quarterly' => $asOf->isSameDay($asOf->copy()->endOfQuarter()),
            'annually', 'yearly' => $asOf->isSameDay($asOf->copy()->endOfYear()),
            default => $asOf->isSameDay($asOf->copy()->endOfMonth()),
        };
    }

    private function interestAmount(SavingsAccount $account, CarbonInterface $asOf): float
    {
        $balance = max(0, (float) $account->current_balance);
        $rate = (float) $account->product->interest_rate / 100;
        $periods = match ($account->product->interest_posting_frequency) {
            'daily' => 365,
            'weekly' => 52,
            'quarterly' => 4,
            'annually', 'yearly' => 1,
            default => 12,
        };

        return round($balance * ($rate / $periods), 2);
    }

    private function periodStart(string $frequency, CarbonInterface $asOf): CarbonInterface
    {
        return match ($frequency) {
            'daily' => $asOf->copy()->startOfDay(),
            'weekly' => $asOf->copy()->startOfWeek(),
            'quarterly' => $asOf->copy()->startOfQuarter(),
            'annually', 'yearly' => $asOf->copy()->startOfYear(),
            default => $asOf->copy()->startOfMonth(),
        };
    }

    private function periodEnd(string $frequency, CarbonInterface $asOf): CarbonInterface
    {
        return match ($frequency) {
            'daily' => $asOf->copy()->endOfDay(),
            'weekly' => $asOf->copy()->endOfWeek(),
            'quarterly' => $asOf->copy()->endOfQuarter(),
            'annually', 'yearly' => $asOf->copy()->endOfYear(),
            default => $asOf->copy()->endOfMonth(),
        };
    }

    private function periodKey(string $frequency, CarbonInterface $asOf): string
    {
        return match ($frequency) {
            'daily' => $asOf->format('Y-m-d'),
            'weekly' => $asOf->format('o-\WW'),
            'quarterly' => $asOf->format('Y').'-Q'.$asOf->quarter,
            'annually', 'yearly' => $asOf->format('Y'),
            default => $asOf->format('Y-m'),
        };
    }
}
