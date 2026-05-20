<?php

namespace App\Services;

use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSecurity;
use App\Models\LockedFund;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FinancialRelationshipService
{
    public function __construct(
        private SavingsTransactionService $savingsTransactions,
        private InvestmentService $investments,
        private LoanRepaymentService $loanRepayments,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{savings_transaction:SavingsTransaction,investment_transaction:InvestmentTransaction,receipt_reference:string}
     */
    public function transferSavingsToInvestment(SavingsAccount $savingsAccount, InvestmentAccount $investmentAccount, float $amount, User $user, ?string $narration = null): array
    {
        $this->assertPositiveMoney($amount);

        return DB::transaction(function () use ($savingsAccount, $investmentAccount, $amount, $user, $narration): array {
            $savingsAccount = SavingsAccount::query()->with('product')->whereKey($savingsAccount->id)->lockForUpdate()->firstOrFail();
            $investmentAccount = InvestmentAccount::query()->with(['plan', 'investorAccount'])->whereKey($investmentAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertSameClient((int) $savingsAccount->client_id, (int) $investmentAccount->client_id);
            $this->assertSavingsCanRelease($savingsAccount, $amount);

            $savingsTransaction = $this->savingsTransactions->withdraw(
                $savingsAccount,
                $amount,
                $user,
                'internal_transfer',
                $narration ?: "Savings transfer to investment {$investmentAccount->investment_number}",
            );

            $investmentTransaction = $this->investments->topUp(
                $investmentAccount,
                $amount,
                $user,
                'internal_transfer',
                $narration ?: "Savings transfer from {$savingsAccount->account_number}",
            );

            $this->auditLogger->record('financial_relationship.savings_to_investment', $investmentTransaction, [
                'savings_transaction_id' => $savingsTransaction->id,
                'investment_transaction_id' => $investmentTransaction->id,
                'amount' => $amount,
            ]);

            return [
                'savings_transaction' => $savingsTransaction,
                'investment_transaction' => $investmentTransaction,
                'receipt_reference' => $savingsTransaction->reference,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function postInvestmentReturn(InvestmentAccount $investmentAccount, float $amount, User $user, string $destination = 'investment', ?SavingsAccount $savingsAccount = null, ?Loan $loan = null, ?string $narration = null): array
    {
        $this->assertPositiveMoney($amount);

        return DB::transaction(function () use ($investmentAccount, $amount, $user, $destination, $savingsAccount, $loan, $narration): array {
            $returnTransaction = $this->investments->postReturn($investmentAccount, $amount, $user, $narration ?: 'Investment return posted');
            $result = ['return_transaction' => $returnTransaction];

            if ($destination === 'investment') {
                return $result;
            }

            if ($destination === 'reinvest') {
                $freshInvestment = $investmentAccount->fresh('plan');

                if ($freshInvestment?->plan?->auto_reinvest_returns) {
                    return $result;
                }

                $result['reinvestment_transaction'] = $this->investments->reinvest($investmentAccount, $amount, $user, $narration ?: 'Investment return reinvested');

                return $result;
            }

            if ($destination === 'savings') {
                if (! $savingsAccount) {
                    throw new InvalidArgumentException('A savings account is required when posting investment returns to savings.');
                }

                $investmentAccount = InvestmentAccount::query()->whereKey($investmentAccount->id)->lockForUpdate()->firstOrFail();
                $savingsAccount = SavingsAccount::query()->whereKey($savingsAccount->id)->lockForUpdate()->firstOrFail();
                $this->assertSameClient((int) $investmentAccount->client_id, (int) $savingsAccount->client_id);
                $this->assertInvestmentCanRelease($investmentAccount, $amount);

                $result['investment_withdrawal_transaction'] = $this->investments->withdraw($investmentAccount, $amount, $user, 'internal_transfer', 'Investment return transferred to savings');
                $result['savings_transaction'] = $this->savingsTransactions->deposit($savingsAccount, $amount, $user, 'internal_transfer', 'Investment return credited to savings');

                return $result;
            }

            if ($destination === 'loan_repayment') {
                if (! $loan) {
                    throw new InvalidArgumentException('A loan is required when posting investment returns to loan repayments.');
                }

                $result['loan_repayment'] = $this->repayLoanFromInvestmentReturns($loan, $investmentAccount, $amount, $user);

                return $result;
            }

            throw new InvalidArgumentException('Unsupported investment return destination.');
        });
    }

    public function lockSavingsForLoan(Loan $loan, SavingsAccount $savingsAccount, float $amount, User $user): LoanSecurity
    {
        $this->assertPositiveMoney($amount);

        return DB::transaction(function () use ($loan, $savingsAccount, $amount, $user): LoanSecurity {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $savingsAccount = SavingsAccount::query()->whereKey($savingsAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertLoanCanAcceptSecurity($loan);
            $this->assertSameClient((int) $loan->client_id, (int) $savingsAccount->client_id);
            $this->assertSavingsCanRelease($savingsAccount, $amount);

            $lockedAmount = (float) $savingsAccount->locked_amount + $amount;
            $savingsAccount->update([
                'locked_amount' => $lockedAmount,
                'available_balance' => max(0, (float) $savingsAccount->current_balance - $lockedAmount),
            ]);
            $loan->update(['locked_savings_amount' => (float) $loan->locked_savings_amount + $amount]);

            $security = LoanSecurity::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'client_id' => $loan->client_id,
                'savings_account_id' => $savingsAccount->id,
                'created_by' => $user->id,
                'type' => 'savings',
                'amount' => $amount,
                'locked_amount' => $amount,
                'status' => 'active',
            ]);
            LockedFund::create([
                'organization_id' => $loan->organization_id,
                'branch_id' => $savingsAccount->branch_id,
                'client_id' => $loan->client_id,
                'source_type' => $savingsAccount->getMorphClass(),
                'source_id' => $savingsAccount->id,
                'related_type' => $loan->getMorphClass(),
                'related_id' => $loan->id,
                'amount' => $amount,
                'reason' => 'loan_security',
                'status' => 'active',
                'locked_by' => $user->id,
            ]);

            $this->auditLogger->record('financial_relationship.savings_locked_for_loan', $security, ['amount' => $amount]);

            return $security;
        });
    }

    public function lockInvestmentForLoan(Loan $loan, InvestmentAccount $investmentAccount, float $amount, User $user): LoanSecurity
    {
        $this->assertPositiveMoney($amount);

        return DB::transaction(function () use ($loan, $investmentAccount, $amount, $user): LoanSecurity {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $investmentAccount = InvestmentAccount::query()->whereKey($investmentAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertLoanCanAcceptSecurity($loan);
            $this->assertSameClient((int) $loan->client_id, (int) $investmentAccount->client_id);
            $this->assertInvestmentCanRelease($investmentAccount, $amount);

            $investmentAccount->update(['locked_amount' => (float) $investmentAccount->locked_amount + $amount]);
            $loan->update(['locked_investment_amount' => (float) $loan->locked_investment_amount + $amount]);

            $security = LoanSecurity::create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->id,
                'client_id' => $loan->client_id,
                'investment_account_id' => $investmentAccount->id,
                'created_by' => $user->id,
                'type' => 'investment',
                'amount' => $amount,
                'locked_amount' => $amount,
                'status' => 'active',
            ]);
            LockedFund::create([
                'organization_id' => $loan->organization_id,
                'branch_id' => $investmentAccount->branch_id,
                'client_id' => $loan->client_id,
                'source_type' => $investmentAccount->getMorphClass(),
                'source_id' => $investmentAccount->id,
                'related_type' => $loan->getMorphClass(),
                'related_id' => $loan->id,
                'amount' => $amount,
                'reason' => 'loan_security',
                'status' => 'active',
                'locked_by' => $user->id,
            ]);

            $this->auditLogger->record('financial_relationship.investment_locked_for_loan', $security, ['amount' => $amount]);

            return $security;
        });
    }

    public function repayLoanFromSavings(Loan $loan, SavingsAccount $savingsAccount, float $amount, User $user): LoanRepayment
    {
        $this->assertPositiveMoney($amount);

        return DB::transaction(function () use ($loan, $savingsAccount, $amount, $user): LoanRepayment {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $savingsAccount = SavingsAccount::query()->with('product')->whereKey($savingsAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertSameClient((int) $loan->client_id, (int) $savingsAccount->client_id);
            $this->assertSavingsCanRelease($savingsAccount, $amount);

            $savingsTransaction = $this->savingsTransactions->withdraw($savingsAccount, $amount, $user, 'internal_transfer', "Loan repayment for {$loan->loan_number}");
            $repayment = $this->loanRepayments->repay($loan, $amount, $user, null, 'savings_deduction', $savingsTransaction->reference, now(), '2100', 'Loan repayment from savings liability');

            $this->auditLogger->record('financial_relationship.loan_repaid_from_savings', $repayment, [
                'savings_transaction_id' => $savingsTransaction->id,
                'amount' => $amount,
            ]);

            return $repayment;
        });
    }

    public function repayLoanFromInvestmentReturns(Loan $loan, InvestmentAccount $investmentAccount, float $amount, User $user): LoanRepayment
    {
        $this->assertPositiveMoney($amount);

        return DB::transaction(function () use ($loan, $investmentAccount, $amount, $user): LoanRepayment {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $investmentAccount = InvestmentAccount::query()->whereKey($investmentAccount->id)->lockForUpdate()->firstOrFail();
            $this->assertSameClient((int) $loan->client_id, (int) $investmentAccount->client_id);
            $this->assertInvestmentCanRelease($investmentAccount, $amount);

            $investmentTransaction = $this->investments->withdraw($investmentAccount, $amount, $user, 'internal_transfer', "Loan repayment for {$loan->loan_number} from investment returns");
            $repayment = $this->loanRepayments->repay($loan, $amount, $user, null, 'investment_return', $investmentTransaction->reference, now(), '2200', 'Loan repayment from investment returns payable');

            $this->auditLogger->record('financial_relationship.loan_repaid_from_investment_returns', $repayment, [
                'investment_transaction_id' => $investmentTransaction->id,
                'amount' => $amount,
            ]);

            return $repayment;
        });
    }

    /**
     * @return array<string, float|int>
     */
    public function clientOverview(Client $client): array
    {
        $client->loadMissing(['savingsAccounts', 'investmentAccounts', 'shareAccounts', 'loans']);

        $totalSavings = (float) $client->savingsAccounts->sum(fn (SavingsAccount $account): float => (float) $account->current_balance);
        $totalInvestments = (float) $client->investmentAccounts->sum(fn (InvestmentAccount $account): float => (float) $account->current_balance);
        $totalShares = (float) $client->shareAccounts->sum(fn ($account): float => (float) $account->total_share_capital);
        $activeLoans = $client->loans->whereIn('status', ['approved', 'active', 'in_arrears']);
        $outstandingLoanBalance = (float) $activeLoans->sum(fn (Loan $loan): float => (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance);
        $lockedSavings = (float) $client->savingsAccounts->sum(fn (SavingsAccount $account): float => (float) $account->locked_amount);
        $lockedInvestment = (float) $client->investmentAccounts->sum(fn (InvestmentAccount $account): float => (float) $account->locked_amount);

        return [
            'total_savings' => round($totalSavings, 2),
            'total_investments' => round($totalInvestments, 2),
            'total_shares' => round($totalShares, 2),
            'active_loans' => $activeLoans->count(),
            'outstanding_loan_balance' => round($outstandingLoanBalance, 2),
            'available_balance' => round(max(0, $totalSavings + $totalInvestments - $lockedSavings - $lockedInvestment), 2),
            'locked_savings' => round($lockedSavings, 2),
            'locked_investment_amount' => round($lockedInvestment, 2),
            'net_financial_position' => round($totalSavings + $totalInvestments + $totalShares - $outstandingLoanBalance, 2),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function combinedStatement(Client $client, ?string $from = null, ?string $to = null): Collection
    {
        $savings = SavingsTransaction::query()
            ->where('client_id', $client->id)
            ->when($from, fn ($query) => $query->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('transaction_date', '<=', $to))
            ->get()
            ->map(fn (SavingsTransaction $transaction): array => [
                'date' => $transaction->transaction_date?->toDateString(),
                'module' => 'savings',
                'type' => $transaction->type,
                'reference' => $transaction->reference,
                'debit' => in_array($transaction->type, ['withdrawal', 'transfer_out'], true) ? (float) $transaction->amount + (float) $transaction->charges : 0.0,
                'credit' => in_array($transaction->type, ['deposit', 'transfer_in'], true) ? (float) $transaction->amount : 0.0,
                'balance' => (float) $transaction->balance_after,
                'narration' => $transaction->narration,
            ]);

        $investments = InvestmentTransaction::query()
            ->whereHas('investmentAccount', fn ($query) => $query->where('client_id', $client->id))
            ->when($from, fn ($query) => $query->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('transaction_date', '<=', $to))
            ->get()
            ->map(fn (InvestmentTransaction $transaction): array => [
                'date' => $transaction->transaction_date?->toDateString(),
                'module' => 'investment',
                'type' => $transaction->type,
                'reference' => $transaction->reference,
                'debit' => in_array($transaction->type, ['withdrawal', 'penalty_deduction'], true) ? (float) $transaction->amount : 0.0,
                'credit' => in_array($transaction->type, ['new_investment_deposit', 'top_up', 'return_posting', 'reinvestment', 'adjustment'], true) ? (float) $transaction->amount : 0.0,
                'balance' => (float) $transaction->balance_after,
                'narration' => $transaction->narration,
            ]);

        $loanDisbursements = $client->loans()
            ->with('disbursements')
            ->get()
            ->flatMap(fn (Loan $loan) => $loan->disbursements->map(fn ($disbursement): array => [
                'date' => $disbursement->disbursement_date?->toDateString(),
                'module' => 'loan',
                'type' => 'disbursement',
                'reference' => $disbursement->transaction_reference,
                'debit' => 0.0,
                'credit' => (float) $disbursement->net_amount,
                'balance' => (float) $loan->principal_balance,
                'narration' => "Loan disbursement {$loan->loan_number}",
            ]));

        $loanRepayments = $client->loans()
            ->with('repayments')
            ->get()
            ->flatMap(fn (Loan $loan) => $loan->repayments->map(fn (LoanRepayment $repayment): array => [
                'date' => $repayment->paid_at?->toDateString(),
                'module' => 'loan',
                'type' => 'repayment',
                'reference' => $repayment->reference,
                'debit' => (float) $repayment->amount,
                'credit' => 0.0,
                'balance' => max(0, (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance),
                'narration' => "Loan repayment {$loan->loan_number}",
                'fees' => (float) $repayment->fees_paid,
                'penalties' => (float) $repayment->penalty_paid,
            ]));

        return $savings
            ->concat($investments)
            ->concat($loanDisbursements)
            ->concat($loanRepayments)
            ->sortBy([['date', 'asc'], ['module', 'asc']])
            ->values();
    }

    private function assertPositiveMoney(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($amount);
    }

    private function assertSameClient(int $leftClientId, int $rightClientId): void
    {
        if ($leftClientId !== $rightClientId) {
            throw new InvalidArgumentException('The selected records must belong to the same client.');
        }
    }

    private function assertSavingsCanRelease(SavingsAccount $account, float $amount): void
    {
        if ($account->status !== 'active') {
            throw new InvalidArgumentException('Savings account must be active.');
        }

        $availableBalance = (float) $account->current_balance - (float) $account->locked_amount;

        if ($amount > $availableBalance) {
            throw new InvalidArgumentException('Amount exceeds unlocked savings balance.');
        }
    }

    private function assertInvestmentCanRelease(InvestmentAccount $account, float $amount): void
    {
        if (! in_array($account->status, ['active', 'matured'], true)) {
            throw new InvalidArgumentException('Investment account must be active or matured.');
        }

        $availableBalance = (float) $account->current_balance - (float) $account->locked_amount;

        if ($amount > $availableBalance) {
            throw new InvalidArgumentException('Amount exceeds unlocked investment balance.');
        }
    }

    private function assertLoanCanAcceptSecurity(Loan $loan): void
    {
        if (! in_array($loan->status, ['approved', 'active', 'in_arrears'], true)) {
            throw new InvalidArgumentException('Only approved or active loans can accept savings or investment security.');
        }
    }
}
