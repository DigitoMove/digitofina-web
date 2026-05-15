<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TransactionPostingService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function postCash(Account $account, DrawerSession $session, TransactionType $type, float $amount, User $user, ?string $description = null, ?string $chequeNumber = null): Transaction
    {
        return DB::transaction(function () use ($account, $session, $type, $amount, $user, $description, $chequeNumber) {
            $account = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $session = DrawerSession::query()->with('drawer')->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $drawer = $session->drawer()->lockForUpdate()->firstOrFail();

            if ($account->status !== 'active') {
                throw new InvalidArgumentException('Account is not active.');
            }

            if ($session->status !== 'open' || $drawer->status !== 'open') {
                throw new InvalidArgumentException('Teller drawer is closed.');
            }

            if ($amount <= 0) {
                throw new InvalidArgumentException('Amount must be greater than zero.');
            }

            $financialControls = app(FinancialControlService::class);
            $businessDate = $financialControls->ensureFinancialDayOpen($user, (int) $drawer->branch_id, now());
            $financialControls->enforceTransactionLimit($user, $amount, $businessDate);

            $direction = $type->direction;

            if (in_array($direction, ['debit', 'withdrawal'], true)) {
                if ((float) $account->balance < $amount) {
                    throw new InvalidArgumentException('Insufficient account balance.');
                }

                if ((float) $drawer->current_balance < $amount) {
                    throw new InvalidArgumentException('Insufficient drawer cash.');
                }

                $account->decrement('balance', $amount);
                $drawer->decrement('current_balance', $amount);
                $movementType = 'cash_out';
            } else {
                $account->increment('balance', $amount);
                $drawer->increment('current_balance', $amount);
                $movementType = 'cash_in';
            }

            $account->refresh();
            $drawer->refresh();

            $transaction = Transaction::create([
                'account_id' => $account->id,
                'transaction_type_id' => $type->id,
                'drawer_session_id' => $session->id,
                'branch_id' => $account->branch_id,
                'user_id' => $user->id,
                'reference' => $this->reference(),
                'cheque_number' => $chequeNumber,
                'amount' => $amount,
                'balance_after' => $account->balance,
                'description' => $description,
            ]);

            DrawerMovement::create([
                'drawer_session_id' => $session->id,
                'user_id' => $user->id,
                'type' => $movementType,
                'amount' => $amount,
                'balance_after' => $drawer->current_balance,
                'reference' => $transaction->reference,
                'description' => $description,
            ]);

            $this->auditLogger->record('transaction.posted', $transaction, [
                'account_number' => $account->account_number,
                'amount' => $amount,
                'type' => $type->code,
            ]);

            return $transaction;
        });
    }

    public function postNonCashCredit(Account $account, TransactionType $type, float $amount, User $user, string $description, array $journals = []): Transaction
    {
        return DB::transaction(function () use ($account, $type, $amount, $user, $description, $journals) {
            $account = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $account->increment('balance', $amount);
            $account->refresh();

            $transaction = Transaction::create([
                'account_id' => $account->id,
                'transaction_type_id' => $type->id,
                'branch_id' => $account->branch_id,
                'user_id' => $user->id,
                'reference' => $this->reference(),
                'amount' => $amount,
                'balance_after' => $account->balance,
                'description' => $description,
            ]);

            foreach ($journals as $journal) {
                $chartAccount = isset($journal['account_code'])
                    ? ChartAccount::query()->where('code', $journal['account_code'])->first()
                    : null;
                $payload = $journal;
                unset($payload['account_code']);

                JournalEntry::create($payload + [
                    'chart_account_id' => $chartAccount?->id,
                    'account_name' => $journal['account_name'] ?? $chartAccount?->name,
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                ]);
            }

            $this->auditLogger->record('transaction.non_cash_credit_posted', $transaction, ['amount' => $amount]);

            return $transaction;
        });
    }

    private function reference(): string
    {
        return 'DF'.now()->format('ymdHis').Str::upper(Str::random(5));
    }
}
