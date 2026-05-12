<?php

namespace App\Services;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SavingsTransactionService
{
    public function deposit(SavingsAccount $account, float $amount, User $teller, string $paymentMethod = 'cash', ?string $narration = null): SavingsTransaction
    {
        return $this->post($account, $amount, $teller, 'deposit', $paymentMethod, $narration);
    }

    public function withdraw(SavingsAccount $account, float $amount, User $teller, string $paymentMethod = 'cash', ?string $narration = null): SavingsTransaction
    {
        return $this->post($account, $amount, $teller, 'withdrawal', $paymentMethod, $narration);
    }

    private function post(SavingsAccount $account, float $amount, User $teller, string $type, string $paymentMethod, ?string $narration): SavingsTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($account, $amount, $teller, $type, $paymentMethod, $narration): SavingsTransaction {
            $account = SavingsAccount::query()->with('product')->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($account->status !== 'active') {
                throw new InvalidArgumentException('Savings account is not active.');
            }

            $balanceBefore = (float) $account->current_balance;
            $charge = $type === 'deposit'
                ? (float) $account->product->deposit_charge
                : (float) $account->product->withdrawal_charge;
            $movement = $amount + $charge;
            $balanceAfter = $type === 'deposit'
                ? $balanceBefore + $amount - $charge
                : $balanceBefore - $movement;

            if ($type === 'withdrawal' && ! $account->product->allows_overdraft && $balanceAfter < (float) $account->product->minimum_balance) {
                throw new InvalidArgumentException('Withdrawal would breach the minimum balance.');
            }

            $account->update([
                'current_balance' => $balanceAfter,
                'available_balance' => $balanceAfter,
            ]);

            return SavingsTransaction::create([
                'organization_id' => $account->organization_id,
                'branch_id' => $account->branch_id,
                'client_id' => $account->client_id,
                'savings_account_id' => $account->id,
                'teller_id' => $teller->id,
                'reference' => 'ST'.now()->format('ymdHis').Str::upper(Str::random(5)),
                'type' => $type,
                'amount' => $amount,
                'charges' => $charge,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'payment_method' => $paymentMethod,
                'narration' => $narration,
                'transaction_date' => now()->toDateString(),
                'value_date' => now()->toDateString(),
                'status' => 'successful',
            ]);
        });
    }
}
