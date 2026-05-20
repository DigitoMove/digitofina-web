<?php

namespace App\Services;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SavingsTransactionService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private JournalEntryService $journalEntryService,
        private FinancialControlService $financialControls,
    ) {}

    public function deposit(SavingsAccount $account, float $amount, User $teller, string $paymentMethod = 'cash', ?string $narration = null): SavingsTransaction
    {
        return $this->post($account, $amount, $teller, 'deposit', $paymentMethod, $narration);
    }

    public function withdraw(SavingsAccount $account, float $amount, User $teller, string $paymentMethod = 'cash', ?string $narration = null): SavingsTransaction
    {
        return $this->post($account, $amount, $teller, 'withdrawal', $paymentMethod, $narration);
    }

    public function reverse(SavingsTransaction $transaction, User $user, string $reason): SavingsTransaction
    {
        if (! $user->canPerform('post-transactions')) {
            throw new InvalidArgumentException('You do not have permission to reverse savings transactions.');
        }

        if ($transaction->status !== 'successful') {
            throw new InvalidArgumentException('Only successful transactions can be reversed.');
        }

        if (! in_array($transaction->type, ['deposit', 'withdrawal'], true)) {
            throw new InvalidArgumentException('Only deposits and withdrawals can be reversed automatically.');
        }

        return DB::transaction(function () use ($transaction, $user, $reason): SavingsTransaction {
            $transaction = SavingsTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $account = SavingsAccount::query()->whereKey($transaction->savings_account_id)->lockForUpdate()->firstOrFail();

            $account->update([
                'current_balance' => $transaction->balance_before,
                'available_balance' => max(0, (float) $transaction->balance_before - (float) $account->locked_amount),
            ]);

            $transaction->update([
                'status' => 'reversed',
                'reversed_by' => $user->id,
                'reversal_reason' => $reason,
            ]);

            $this->auditLogger->record('savings.transaction_reversed', $transaction, ['reason' => $reason]);

            return $transaction->refresh();
        });
    }

    /**
     * @return array{out:SavingsTransaction,in:SavingsTransaction}
     */
    public function transfer(SavingsAccount $fromAccount, SavingsAccount $toAccount, float $amount, User $user, ?string $narration = null): array
    {
        if ($fromAccount->is($toAccount)) {
            throw new InvalidArgumentException('Transfer accounts must be different.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($fromAccount, $toAccount, $amount, $user, $narration): array {
            $orderedIds = collect([$fromAccount->id, $toAccount->id])->sort()->values();
            $locked = SavingsAccount::query()
                ->with('product')
                ->whereIn('id', $orderedIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $locked->get($fromAccount->id);
            $to = $locked->get($toAccount->id);

            if (! $from || ! $to) {
                throw new InvalidArgumentException('Savings account not found.');
            }

            foreach ([$from, $to] as $account) {
                if ($account->status !== 'active') {
                    throw new InvalidArgumentException('Both savings accounts must be active.');
                }
            }

            $fromBefore = (float) $from->current_balance;
            $toBefore = (float) $to->current_balance;
            $fromAfter = $fromBefore - $amount;

            if (! $from->product->allows_overdraft && $fromAfter < (float) $from->product->minimum_balance) {
                throw new InvalidArgumentException('Transfer would breach the source account minimum balance.');
            }

            if ($amount > ($fromBefore - (float) $from->locked_amount)) {
                throw new InvalidArgumentException('Transfer exceeds the unlocked savings balance.');
            }

            $toAfter = $toBefore + $amount;
            $reference = 'TR'.now()->format('ymdHis').Str::upper(Str::random(5));

            $from->update(['current_balance' => $fromAfter, 'available_balance' => max(0, $fromAfter - (float) $from->locked_amount)]);
            $to->update(['current_balance' => $toAfter, 'available_balance' => max(0, $toAfter - (float) $to->locked_amount)]);

            $out = $this->createTransaction($from, $user, $reference.'O', 'transfer_out', $amount, 0, $fromBefore, $fromAfter, 'internal_transfer', $narration);
            $in = $this->createTransaction($to, $user, $reference.'I', 'transfer_in', $amount, 0, $toBefore, $toAfter, 'internal_transfer', $narration);

            $this->auditLogger->record('savings.transfer_posted', $out, [
                'from_account' => $from->account_number,
                'to_account' => $to->account_number,
                'amount' => $amount,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    private function post(SavingsAccount $account, float $amount, User $teller, string $type, string $paymentMethod, ?string $narration): SavingsTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($account, $amount, $teller, $type, $paymentMethod, $narration): SavingsTransaction {
            $account = SavingsAccount::query()->with('product')->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $businessDate = $this->financialControls->ensureFinancialDayOpen($teller, (int) $account->branch_id);
            $this->financialControls->enforceTransactionLimit($teller, $amount, $businessDate);

            if ($type === 'withdrawal' && $this->financialControls->requiresMakerChecker($teller, 'savings_withdrawal', $amount)) {
                throw new InvalidArgumentException('Large withdrawals require maker-checker approval before posting.');
            }

            if ($account->status !== 'active') {
                throw new InvalidArgumentException('Savings account is not active.');
            }

            $balanceBefore = (float) $account->current_balance;
            $charge = $type === 'deposit'
                ? (float) $account->product->deposit_charge
                : (float) $account->product->withdrawal_charge;

            if ($type === 'deposit' && $charge >= $amount) {
                throw new InvalidArgumentException('Deposit amount must exceed deposit charges.');
            }

            $movement = $amount + $charge;
            $balanceAfter = $type === 'deposit'
                ? $balanceBefore + $amount - $charge
                : $balanceBefore - $movement;

            if ($type === 'withdrawal' && ! $account->product->allows_overdraft && $balanceAfter < (float) $account->product->minimum_balance) {
                throw new InvalidArgumentException('Withdrawal would breach the minimum balance.');
            }

            if ($type === 'withdrawal' && $movement > ($balanceBefore - (float) $account->locked_amount)) {
                throw new InvalidArgumentException('Withdrawal exceeds the unlocked savings balance.');
            }

            $account->update([
                'current_balance' => $balanceAfter,
                'available_balance' => max(0, $balanceAfter - (float) $account->locked_amount),
                'last_activity_at' => now(),
                'dormant_at' => null,
            ]);

            $transaction = $this->createTransaction(
                account: $account,
                teller: $teller,
                reference: 'ST'.now()->format('ymdHis').Str::upper(Str::random(5)),
                type: $type,
                amount: $amount,
                charges: $charge,
                balanceBefore: $balanceBefore,
                balanceAfter: $balanceAfter,
                paymentMethod: $paymentMethod,
                narration: $narration,
            );

            $this->postAccounting($transaction, $type, $amount, $charge);
            $this->auditLogger->record("savings.{$type}_posted", $transaction, [
                'account_number' => $account->account_number,
                'amount' => $amount,
                'charges' => $charge,
            ]);
            $this->financialControls->recordSuspiciousTransaction($teller, $transaction, $amount, "savings_{$type}", $account->branch_id);

            return $transaction;
        });
    }

    private function createTransaction(SavingsAccount $account, User $teller, string $reference, string $type, float $amount, float $charges, float $balanceBefore, float $balanceAfter, string $paymentMethod, ?string $narration): SavingsTransaction
    {
        return SavingsTransaction::create([
            'organization_id' => $account->organization_id,
            'branch_id' => $account->branch_id,
            'client_id' => $account->client_id,
            'savings_account_id' => $account->id,
            'teller_id' => $teller->id,
            'reference' => $reference,
            'type' => $type,
            'amount' => $amount,
            'charges' => $charges,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'payment_method' => $paymentMethod,
            'narration' => $narration,
            'transaction_date' => now()->toDateString(),
            'value_date' => now()->toDateString(),
            'status' => 'successful',
        ]);
    }

    private function postAccounting(SavingsTransaction $transaction, string $type, float $amount, float $charge): void
    {
        $netSavings = $type === 'deposit' ? $amount - $charge : $amount + $charge;
        $lines = $type === 'deposit'
            ? [
                ['account_code' => '1000', 'debit' => $amount, 'narration' => 'Savings deposit cash received'],
                ['account_code' => '2100', 'credit' => $netSavings, 'narration' => 'Client savings liability increased'],
            ]
            : [
                ['account_code' => '2100', 'debit' => $netSavings, 'narration' => 'Client savings liability reduced'],
                ['account_code' => '1000', 'credit' => $amount, 'narration' => 'Savings withdrawal cash paid'],
            ];

        if ($charge > 0) {
            $lines[] = ['account_code' => '4200', 'credit' => $charge, 'narration' => 'Savings transaction charge'];
        }

        $this->journalEntryService->post($lines, "Savings {$type} {$transaction->reference}", $transaction->teller, $transaction->branch_id);
    }
}
