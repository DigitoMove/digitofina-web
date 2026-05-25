<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientRelationship;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\InvestmentReturn;
use App\Models\InvestmentTransaction;
use App\Models\InvestorAccount;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InvestmentService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private JournalEntryService $journalEntryService,
        private FinancialControlService $financialControls,
    ) {}

    public function createPlan(array $data, User $user): InvestmentPlan
    {
        $isSharePackage = (bool) ($data['is_share_package'] ?? in_array($data['type'] ?? null, ['share_package', 'asset_backed'], true));
        $unitValue = (float) ($data['unit_value'] ?? 0);
        $minimumUnits = (int) ($data['minimum_units'] ?? 1);
        $maximumUnits = $data['maximum_units'] ?? null;
        $minimumAmount = $data['minimum_amount'] ?? $data['min_amount'] ?? ($isSharePackage ? $unitValue * $minimumUnits : 0);
        $maximumAmount = $data['maximum_amount'] ?? $data['max_amount'] ?? ($isSharePackage && $maximumUnits !== null ? $unitValue * (int) $maximumUnits : null);

        $plan = InvestmentPlan::create($data + [
            'organization_id' => $user->organization_id,
            'branch_id' => $data['branch_id'] ?? $user->branch_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'unit_label' => $data['unit_label'] ?? 'share',
            'unit_value' => $unitValue,
            'minimum_units' => $minimumUnits,
            'maximum_units' => $maximumUnits,
            'is_share_package' => $isSharePackage,
            'min_amount' => $data['min_amount'] ?? $minimumAmount,
            'max_amount' => $data['max_amount'] ?? $maximumAmount,
            'minimum_amount' => $minimumAmount,
            'maximum_amount' => $maximumAmount,
            'monthly_maintenance_fee' => $data['monthly_maintenance_fee'] ?? 0,
            'min_holding_months' => $data['min_holding_months'] ?? 36,
            'max_holding_months' => $data['max_holding_months'] ?? 60,
            'return_method' => $data['return_method'] ?? $data['return_calculation_method'] ?? 'fixed_percentage',
            'return_calculation_method' => $data['return_calculation_method'] ?? $data['return_method'] ?? 'fixed_percentage',
            'allow_topups' => $data['allow_topups'] ?? $data['allow_top_ups'] ?? false,
            'allow_top_ups' => $data['allow_top_ups'] ?? $data['allow_topups'] ?? false,
            'early_withdrawal_penalty_type' => $data['early_withdrawal_penalty_type'] ?? 'percentage',
            'early_withdrawal_penalty_value' => $data['early_withdrawal_penalty_value'] ?? $data['early_withdrawal_penalty'] ?? 0,
            'early_withdrawal_penalty' => $data['early_withdrawal_penalty'] ?? $data['early_withdrawal_penalty_value'] ?? 0,
            'auto_reinvest' => $data['auto_reinvest'] ?? $data['auto_reinvest_returns'] ?? false,
            'auto_reinvest_returns' => $data['auto_reinvest_returns'] ?? $data['auto_reinvest'] ?? false,
            'default_required_information' => $data['default_required_information'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->auditLogger->record('investment.plan_created', $plan, $plan->toArray());

        return $plan;
    }

    public function createInvestorAccount(array $data, User $user): InvestorAccount
    {
        $client = Client::findOrFail($data['client_id']);

        if ($client->status !== 'active') {
            throw new InvalidArgumentException('Client must be active before creating an investor account.');
        }

        return DB::transaction(function () use ($data, $user, $client): InvestorAccount {
            $account = InvestorAccount::create([
                'organization_id' => $client->organization_id ?: $user->organization_id,
                'client_id' => $client->id,
                'branch_id' => $data['branch_id'] ?? $client->branch_id,
                'assigned_officer_id' => $data['assigned_officer_id'] ?? $client->officer_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'account_number' => $this->investorAccountNumber($client),
                'status' => $data['status'] ?? 'active',
            ]);

            $this->auditLogger->record('investment.investor_account_created', $account, $account->toArray());

            return $account;
        });
    }

    public function createInvestment(array $data, User $user): InvestmentAccount
    {
        return DB::transaction(function () use ($data, $user): InvestmentAccount {
            $investor = InvestorAccount::query()->with('client')->whereKey($data['investor_account_id'])->lockForUpdate()->firstOrFail();
            $plan = InvestmentPlan::query()->whereKey($data['investment_plan_id'])->lockForUpdate()->firstOrFail();
            $unitQuantity = $this->investmentUnits($data, $plan);
            $principal = $this->investmentPrincipal($data, $plan, $unitQuantity);
            $sponsorClientId = $this->sponsorClientId($data, $investor);

            $this->assertInvestorCanTransact($investor);
            $this->assertPlanCanBeUsed($plan, $principal);
            $this->assertPlanUnitsCanBeUsed($plan, $unitQuantity);
            $businessDate = $this->financialControls->ensureFinancialDayOpen($user, (int) $investor->branch_id, $data['start_date'] ?? today());
            $this->financialControls->enforceTransactionLimit($user, $principal, $businessDate);

            $startDate = CarbonImmutable::parse($data['start_date'] ?? today()->toDateString());
            $targetDurationMonths = $this->targetDurationMonths($data, $plan);
            $maturityDate = $targetDurationMonths !== null
                ? $startDate->addMonthsNoOverflow($targetDurationMonths)
                : $this->maturityDate($startDate, $plan);
            $expectedReturn = $this->calculateReturn($principal, $plan);
            $requiresApproval = $principal >= $this->approvalThreshold($user);

            $investment = InvestmentAccount::create([
                'organization_id' => $investor->organization_id,
                'investor_account_id' => $investor->id,
                'client_id' => $investor->client_id,
                'sponsor_client_id' => $sponsorClientId,
                'investment_plan_id' => $plan->id,
                'unit_quantity' => $unitQuantity,
                'unit_value' => $plan->unit_value ?: $principal,
                'unit_label' => $plan->unit_label ?: 'share',
                'branch_id' => $investor->branch_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'investment_number' => $this->investmentNumber($investor),
                'principal_amount' => $principal,
                'start_date' => $startDate->toDateString(),
                'maturity_date' => $maturityDate->toDateString(),
                'target_duration_months' => $targetDurationMonths,
                'expected_return_amount' => $expectedReturn,
                'actual_return_amount' => 0,
                'current_balance' => 0,
                'return_rate' => $plan->expected_return_rate,
                'monthly_maintenance_fee' => $plan->monthly_maintenance_fee ?? 0,
                'maintenance_fee_paid' => 0,
                'return_method' => $plan->return_calculation_method,
                'client_investment_details' => $data['client_investment_details'] ?? null,
                'status' => $requiresApproval ? 'pending_approval' : 'active',
            ]);

            if ($requiresApproval) {
                $this->financialControls->requestAction($user, 'investment_approval', $investment, [
                    'principal_amount' => $principal,
                    'payment_method' => $data['payment_method'],
                    'narration' => $data['narration'] ?? null,
                ], $principal, 'approve-transactions', $data['narration'] ?? null);
            } else {
                $this->applyInvestmentFunding($investment, $investor, $principal, $user, $data['payment_method'], $data['narration'] ?? 'New investment deposit', $data['idempotency_key'] ?? null, 'new_investment_deposit');
            }

            $this->auditLogger->record('investment.account_created', $investment, $investment->toArray());

            return $investment->refresh();
        });
    }

    public function approveInvestment(InvestmentAccount $investment, User $user, string $paymentMethod = 'cash', ?string $narration = null): InvestmentAccount
    {
        return DB::transaction(function () use ($investment, $user, $paymentMethod, $narration): InvestmentAccount {
            $investment = InvestmentAccount::query()->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            $investor = InvestorAccount::query()->whereKey($investment->investor_account_id)->lockForUpdate()->firstOrFail();

            if ($investment->status !== 'pending_approval') {
                throw new InvalidArgumentException('Only pending approval investments can be approved.');
            }

            $investment->update([
                'status' => 'active',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            $this->applyInvestmentFunding($investment, $investor, (float) $investment->principal_amount, $user, $paymentMethod, $narration ?: 'Approved investment deposit', null, 'new_investment_deposit');
            $this->auditLogger->record('investment.account_approved', $investment->refresh(), ['approved_by' => $user->id]);

            return $investment->refresh();
        });
    }

    public function topUp(InvestmentAccount $investment, float $amount, User $user, string $paymentMethod, ?string $narration = null, ?string $idempotencyKey = null): InvestmentTransaction
    {
        return DB::transaction(function () use ($investment, $amount, $user, $paymentMethod, $narration, $idempotencyKey): InvestmentTransaction {
            $investment = InvestmentAccount::query()->with('plan')->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            $investor = InvestorAccount::query()->whereKey($investment->investor_account_id)->lockForUpdate()->firstOrFail();

            if (! $investment->plan->allow_top_ups) {
                throw new InvalidArgumentException('This investment plan does not allow top-ups.');
            }

            if ($investment->status !== 'active') {
                throw new InvalidArgumentException('Only active investments can receive top-ups.');
            }

            return $this->applyInvestmentFunding($investment, $investor, $amount, $user, $paymentMethod, $narration ?: 'Investment top-up', $idempotencyKey, 'top_up');
        });
    }

    public function postReturn(InvestmentAccount $investment, float $amount, User $user, ?string $narration = null, ?string $idempotencyKey = null): InvestmentTransaction
    {
        return DB::transaction(function () use ($investment, $amount, $user, $narration, $idempotencyKey): InvestmentTransaction {
            $investment = InvestmentAccount::query()->with('plan')->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            $investor = InvestorAccount::query()->whereKey($investment->investor_account_id)->lockForUpdate()->firstOrFail();
            $this->assertPositiveMoney($amount);

            if (! in_array($investment->status, ['active', 'matured'], true)) {
                throw new InvalidArgumentException('Returns can only be posted to active or matured investments.');
            }

            $balanceBefore = (float) $investment->current_balance;
            $balanceAfter = $balanceBefore + $amount;
            $investment->update([
                'actual_return_amount' => (float) $investment->actual_return_amount + $amount,
                'current_balance' => $balanceAfter,
            ]);
            $investor->update([
                'total_returns_earned' => (float) $investor->total_returns_earned + $amount,
                'current_investment_balance' => (float) $investor->current_investment_balance + $amount,
                'current_balance' => (float) $investor->current_balance + $amount,
            ]);

            $transaction = $this->createTransaction($investor, $investment, $user, 'return_posting', $amount, $balanceBefore, $balanceAfter, 'internal_transfer', $narration, $idempotencyKey);
            InvestmentReturn::create([
                'organization_id' => $investment->organization_id,
                'branch_id' => $investment->branch_id,
                'investment_account_id' => $investment->id,
                'client_id' => $investment->client_id,
                'expected_amount' => (float) $investment->expected_return_amount,
                'actual_amount' => $amount,
                'return_rate' => $investment->return_rate,
                'period_start' => $investment->start_date,
                'period_end' => today()->toDateString(),
                'posting_date' => today()->toDateString(),
                'status' => 'posted',
                'posted_by' => $user->id,
                'narration' => $narration,
            ]);
            $this->journalEntryService->post([
                ['account_code' => '5000', 'debit' => $amount, 'narration' => 'Investment return expense'],
                ['account_code' => '2200', 'credit' => $amount, 'narration' => 'Investment returns payable'],
            ], "Investment return {$transaction->reference}", $user, $transaction->branch_id);
            $this->auditLogger->record('investment.return_posted', $transaction, ['amount' => $amount]);

            if ($investment->plan->auto_reinvest_returns) {
                $this->reinvest($investment, $amount, $user, 'Auto reinvested return');
            }

            return $transaction;
        });
    }

    public function withdraw(InvestmentAccount $investment, float $amount, User $user, string $paymentMethod, ?string $narration = null, ?string $idempotencyKey = null): InvestmentTransaction
    {
        return DB::transaction(function () use ($investment, $amount, $user, $paymentMethod, $narration, $idempotencyKey): InvestmentTransaction {
            $investment = InvestmentAccount::query()->with('plan')->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            $investor = InvestorAccount::query()->whereKey($investment->investor_account_id)->lockForUpdate()->firstOrFail();
            $this->assertPositiveMoney($amount);

            if (! in_array($investment->status, ['active', 'matured'], true)) {
                throw new InvalidArgumentException('Only active or matured investments can be withdrawn.');
            }

            $isEarly = $investment->maturity_date->isFuture();

            if ($isEarly && ! $investment->plan->allow_early_withdrawal) {
                throw new InvalidArgumentException('This investment plan does not allow early withdrawal.');
            }

            $penalty = $isEarly ? $this->earlyWithdrawalPenalty($investment, $amount) : 0.0;
            $totalDebit = $amount + $penalty;
            $balanceBefore = (float) $investment->current_balance;

            if ($totalDebit > $balanceBefore) {
                throw new InvalidArgumentException('Withdrawal exceeds the current investment balance.');
            }

            if ($totalDebit > ($balanceBefore - (float) $investment->locked_amount)) {
                throw new InvalidArgumentException('Withdrawal exceeds the unlocked investment balance.');
            }

            $balanceAfter = $balanceBefore - $totalDebit;
            $investment->update([
                'current_balance' => $balanceAfter,
                'status' => $balanceAfter <= 0 ? 'withdrawn' : $investment->status,
                'closed_at' => $balanceAfter <= 0 ? now() : null,
            ]);
            $investor->update([
                'total_withdrawn' => (float) $investor->total_withdrawn + $amount,
                'current_investment_balance' => (float) $investor->current_investment_balance - $totalDebit,
                'current_balance' => (float) $investor->current_balance - $totalDebit,
            ]);

            if ($penalty > 0) {
                $this->createTransaction($investor, $investment, $user, 'penalty_deduction', $penalty, $balanceBefore, $balanceBefore - $penalty, 'internal_transfer', 'Early withdrawal penalty', null);
            }

            $transaction = $this->createTransaction($investor, $investment, $user, 'withdrawal', $amount, $balanceBefore - $penalty, $balanceAfter, $paymentMethod, $narration, $idempotencyKey);
            $lines = [
                ['account_code' => '2200', 'debit' => $amount + $penalty, 'narration' => 'Investment liability reduced'],
                ['account_code' => '1000', 'credit' => $amount, 'narration' => 'Investment withdrawal paid'],
            ];

            if ($penalty > 0) {
                $lines[] = ['account_code' => '4200', 'credit' => $penalty, 'narration' => 'Investment early withdrawal penalty'];
            }

            $this->journalEntryService->post($lines, "Investment withdrawal {$transaction->reference}", $user, $transaction->branch_id);
            $this->auditLogger->record('investment.withdrawal_posted', $transaction, ['amount' => $amount, 'penalty' => $penalty]);

            return $transaction;
        });
    }

    public function reinvest(InvestmentAccount $investment, float $amount, User $user, ?string $narration = null): InvestmentTransaction
    {
        return DB::transaction(function () use ($investment, $amount, $user, $narration): InvestmentTransaction {
            $investment = InvestmentAccount::query()->with('plan')->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            $investor = InvestorAccount::query()->whereKey($investment->investor_account_id)->lockForUpdate()->firstOrFail();

            if (! $investment->plan->allow_top_ups) {
                throw new InvalidArgumentException('This investment plan does not allow reinvestments.');
            }

            if (! in_array($investment->status, ['active', 'matured'], true)) {
                throw new InvalidArgumentException('Only active or matured investments can receive reinvestments.');
            }

            $this->assertPositiveMoney($amount);
            $balance = (float) $investment->current_balance;
            $principal = (float) $investment->principal_amount + $amount;
            $investment->update([
                'principal_amount' => $principal,
                'expected_return_amount' => $this->calculateReturn($principal, $investment->plan),
            ]);
            $investor->update(['total_invested' => (float) $investor->total_invested + $amount]);

            $transaction = $this->createTransaction($investor, $investment, $user, 'reinvestment', $amount, $balance, $balance, 'internal_transfer', $narration ?: 'Investment reinvestment', null);
            $this->journalEntryService->post([
                ['account_code' => '2200', 'debit' => $amount, 'narration' => 'Investment return reinvested'],
                ['account_code' => '2200', 'credit' => $amount, 'narration' => 'Investment principal increased by reinvestment'],
            ], "Investment reinvestment {$transaction->reference}", $user, $transaction->branch_id);
            $this->auditLogger->record('investment.reinvestment_posted', $transaction, ['amount' => $amount]);

            return $transaction;
        });
    }

    public function markMatured(): int
    {
        return DB::transaction(function (): int {
            $investments = InvestmentAccount::query()
                ->where('status', 'active')
                ->whereDate('maturity_date', '<=', today())
                ->lockForUpdate()
                ->get();

            foreach ($investments as $investment) {
                $investment->update(['status' => 'matured']);
                $this->auditLogger->record('investment.marked_matured', $investment, ['maturity_date' => $investment->maturity_date?->toDateString()]);
            }

            return $investments->count();
        });
    }

    private function applyInvestmentFunding(InvestmentAccount $investment, InvestorAccount $investor, float $amount, User $user, string $paymentMethod, ?string $narration, ?string $idempotencyKey, string $type): InvestmentTransaction
    {
        $this->assertPositiveMoney($amount);
        $balanceBefore = (float) $investment->current_balance;
        $balanceAfter = $balanceBefore + $amount;

        $principal = $type === 'new_investment_deposit'
            ? (float) $investment->principal_amount
            : (float) $investment->principal_amount + $amount;

        $investment->update([
            'principal_amount' => $principal,
            'current_balance' => $balanceAfter,
            'expected_return_amount' => $this->calculateReturn($principal, $investment->plan),
        ]);

        $investor->update([
            'total_invested' => (float) $investor->total_invested + $amount,
            'current_investment_balance' => (float) $investor->current_investment_balance + $amount,
            'current_balance' => (float) $investor->current_balance + $amount,
        ]);

        $transaction = $this->createTransaction($investor, $investment, $user, $type, $amount, $balanceBefore, $balanceAfter, $paymentMethod, $narration, $idempotencyKey);
        $this->journalEntryService->post([
            ['account_code' => '1000', 'debit' => $amount, 'narration' => 'Investment cash received'],
            ['account_code' => '2200', 'credit' => $amount, 'narration' => 'Investment liability increased'],
        ], "Investment funding {$transaction->reference}", $user, $transaction->branch_id);
        $this->auditLogger->record("investment.{$type}_posted", $transaction, ['amount' => $amount]);
        $this->financialControls->recordSuspiciousTransaction($user, $transaction, $amount, "investment_{$type}", $transaction->branch_id);

        return $transaction;
    }

    private function createTransaction(InvestorAccount $investor, ?InvestmentAccount $investment, User $user, string $type, float $amount, float $balanceBefore, float $balanceAfter, string $paymentMethod, ?string $narration, ?string $idempotencyKey): InvestmentTransaction
    {
        $reference = 'IV'.now()->format('ymdHis').Str::upper(Str::random(5));

        return InvestmentTransaction::create([
            'organization_id' => $investor->organization_id,
            'investor_account_id' => $investor->id,
            'investment_account_id' => $investment?->id,
            'client_id' => $investor->client_id,
            'branch_id' => $investor->branch_id,
            'created_by' => $user->id,
            'reference' => $reference,
            'transaction_reference' => $reference,
            'idempotency_key' => $idempotencyKey,
            'type' => $type,
            'transaction_type' => $type,
            'amount' => $amount,
            'charges' => 0,
            'penalty_amount' => $type === 'penalty_deduction' ? $amount : 0,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'payment_method' => $paymentMethod,
            'narration' => $narration,
            'transaction_date' => today()->toDateString(),
            'status' => 'successful',
        ]);
    }

    private function calculateReturn(float $principal, InvestmentPlan $plan): float
    {
        $rate = (float) $plan->expected_return_rate / 100;
        $years = max(1 / 365, match ($plan->duration_unit) {
            'days' => $plan->duration / 365,
            'weeks' => ($plan->duration * 7) / 365,
            'months' => $plan->duration / 12,
            'years' => $plan->duration,
            default => $plan->duration / 12,
        });

        return round(match ($plan->return_calculation_method) {
            'simple_interest' => $principal * $rate * $years,
            'compound_interest' => $principal * ((1 + $rate) ** $years - 1),
            'profit_share', 'fixed_percentage' => $principal * $rate,
            'manual_return_posting' => 0,
            default => $principal * $rate,
        }, 2);
    }

    /**
     * @return array<string, float|int|bool>
     */
    public function earlyWithdrawalQuote(InvestmentAccount $investment): array
    {
        $investment->loadMissing('plan');

        $effectiveRate = $this->effectiveEarlyWithdrawalReturnRate($investment);
        $principal = (float) $investment->principal_amount;
        $earnedReturn = round($principal * ($effectiveRate / 100), 2);
        $fullTermReturn = round($principal * ((float) $investment->return_rate / 100), 2);

        return [
            'is_early' => (bool) ($investment->maturity_date?->isFuture() ?? false),
            'elapsed_months' => $investment->elapsedMonths(),
            'minimum_months' => max(1, (int) ($investment->plan?->min_holding_months ?? 36)),
            'effective_return_rate' => $effectiveRate,
            'earned_return_amount' => $earnedReturn,
            'unearned_return_amount' => max(0, round($fullTermReturn - $earnedReturn, 2)),
        ];
    }

    public function effectiveEarlyWithdrawalReturnRate(InvestmentAccount $investment): float
    {
        $investment->loadMissing('plan');

        $minimumMonths = max(1, (int) ($investment->plan?->min_holding_months ?? 36));
        $elapsedMonths = min($minimumMonths, $investment->elapsedMonths());

        return round(((float) $investment->return_rate) * ($elapsedMonths / $minimumMonths), 4);
    }

    private function maturityDate(CarbonImmutable $startDate, InvestmentPlan $plan): CarbonImmutable
    {
        return match ($plan->duration_unit) {
            'days' => $startDate->addDays((int) $plan->duration),
            'weeks' => $startDate->addWeeks((int) $plan->duration),
            'months' => $startDate->addMonthsNoOverflow((int) $plan->duration),
            'years' => $startDate->addYearsNoOverflow((int) $plan->duration),
            default => $startDate->addMonthsNoOverflow((int) $plan->duration),
        };
    }

    private function targetDurationMonths(array $data, InvestmentPlan $plan): ?int
    {
        if (! array_key_exists('target_duration_months', $data) || blank($data['target_duration_months'])) {
            return null;
        }

        $targetDurationMonths = (int) $data['target_duration_months'];
        $minimumMonths = max(1, (int) ($plan->min_holding_months ?? 36));
        $maximumMonths = max($minimumMonths, (int) ($plan->max_holding_months ?? 60));

        if ($targetDurationMonths < $minimumMonths || $targetDurationMonths > $maximumMonths) {
            throw new InvalidArgumentException("Investment duration must be between {$minimumMonths} and {$maximumMonths} months for this package.");
        }

        return $targetDurationMonths;
    }

    private function earlyWithdrawalPenalty(InvestmentAccount $investment, float $amount): float
    {
        $planPenalty = round($amount * ((float) $investment->plan->early_withdrawal_penalty / 100), 2);
        $quote = $this->earlyWithdrawalQuote($investment);
        $postedReturn = max(0, (float) $investment->actual_return_amount);

        if ($postedReturn <= 0) {
            return $planPenalty;
        }

        $withdrawalShare = min(1, $amount / max(1, (float) $investment->principal_amount));
        $allowedPostedReturn = (float) $quote['earned_return_amount'] * $withdrawalShare;
        $unearnedPostedReturn = max(0, ($postedReturn * $withdrawalShare) - $allowedPostedReturn);

        return round($planPenalty + $unearnedPostedReturn, 2);
    }

    private function assertInvestorCanTransact(InvestorAccount $investor): void
    {
        if ($investor->status !== 'active') {
            throw new InvalidArgumentException('Investor account must be active.');
        }

        if ($investor->client?->status !== 'active') {
            throw new InvalidArgumentException('Client must be active.');
        }
    }

    private function assertPlanCanBeUsed(InvestmentPlan $plan, float $amount): void
    {
        if ($plan->status !== 'active') {
            throw new InvalidArgumentException('Investment plan must be active before use.');
        }

        if ($amount < (float) $plan->minimum_amount) {
            throw new InvalidArgumentException('Investment amount is below the plan minimum.');
        }

        if ($plan->maximum_amount !== null && $amount > (float) $plan->maximum_amount) {
            throw new InvalidArgumentException('Investment amount exceeds the plan maximum.');
        }
    }

    private function assertPlanUnitsCanBeUsed(InvestmentPlan $plan, int $units): void
    {
        if ($units < (int) $plan->minimum_units) {
            throw new InvalidArgumentException("Investment requires at least {$plan->minimum_units} {$plan->unit_label}(s).");
        }

        if ($plan->maximum_units !== null && $units > (int) $plan->maximum_units) {
            throw new InvalidArgumentException("Investment cannot exceed {$plan->maximum_units} {$plan->unit_label}(s).");
        }
    }

    private function investmentUnits(array $data, InvestmentPlan $plan): int
    {
        if (array_key_exists('unit_quantity', $data) && $data['unit_quantity'] !== null) {
            return (int) $data['unit_quantity'];
        }

        if ((bool) $plan->is_share_package) {
            return (int) $plan->minimum_units;
        }

        return 1;
    }

    private function investmentPrincipal(array $data, InvestmentPlan $plan, int $units): float
    {
        $principal = array_key_exists('principal_amount', $data) && $data['principal_amount'] !== null
            ? (float) $data['principal_amount']
            : (float) $plan->unit_value * $units;

        if ($principal <= 0) {
            throw new InvalidArgumentException('Principal amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($principal, 'Principal amount');

        return $principal;
    }

    private function sponsorClientId(array $data, InvestorAccount $investor): ?int
    {
        if (blank($data['sponsor_client_id'] ?? null)) {
            return null;
        }

        $sponsor = Client::query()->whereKey($data['sponsor_client_id'])->firstOrFail();

        if ((int) $sponsor->id === (int) $investor->client_id) {
            return null;
        }

        $isRelated = ClientRelationship::query()
            ->where('status', 'active')
            ->where(function ($query) use ($sponsor, $investor): void {
                $query->where(function ($query) use ($sponsor, $investor): void {
                    $query->where('client_id', $sponsor->id)
                        ->where('related_client_id', $investor->client_id);
                })->orWhere(function ($query) use ($sponsor, $investor): void {
                    $query->where('client_id', $investor->client_id)
                        ->where('related_client_id', $sponsor->id);
                });
            })
            ->exists();

        if (! $isRelated) {
            throw new InvalidArgumentException('Sponsor client must be attached as an active relative of the investor client.');
        }

        return (int) $sponsor->id;
    }

    private function assertPositiveMoney(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($amount);
    }

    private function approvalThreshold(User $user): float
    {
        $setting = SystemSetting::forOrganization($user->organization_id)
            ->where('key', 'investment_approval_threshold')
            ->first();

        return (float) ($setting?->value ?? 5_000_000);
    }

    private function investorAccountNumber(Client $client): string
    {
        return ($client->branch?->code ?? str_pad((string) $client->branch_id, 4, '0', STR_PAD_LEFT)).'-INVESTOR-'.str_pad((string) (InvestorAccount::count() + 1), 7, '0', STR_PAD_LEFT);
    }

    private function investmentNumber(InvestorAccount $investor): string
    {
        return ($investor->branch?->code ?? str_pad((string) $investor->branch_id, 4, '0', STR_PAD_LEFT)).'-INV-'.str_pad((string) (InvestmentAccount::count() + 1), 7, '0', STR_PAD_LEFT);
    }
}
