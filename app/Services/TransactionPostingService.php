<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TransactionPostingService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function postCash(Account $account, DrawerSession $session, TransactionType $type, float $amount, User $user, ?string $description = null, ?string $chequeNumber = null): Transaction
    {
        return DB::transaction(function () use ($account, $session, $type, $amount, $user, $description, $chequeNumber) {
            $account = Account::query()->with(['accountType', 'client.loans'])->whereKey($account->id)->lockForUpdate()->firstOrFail();
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

            CurrencyDenomination::assertValid($amount);

            $financialControls = app(FinancialControlService::class);
            $businessDate = $financialControls->ensureFinancialDayOpen($user, (int) $drawer->branch_id, now());
            $financialControls->enforceTransactionLimit($user, $amount, $businessDate);

            $direction = $type->direction;

            if ($account->accountType?->category === 'loan' && $type->code === 'loan_repayment') {
                return $this->postLoanRepayment($account, $session, $drawer, $type, $amount, $user, $description, $chequeNumber);
            }

            if ($account->accountType?->category === 'loan') {
                throw new InvalidArgumentException('Loan accounts only accept loan repayment transactions.');
            }

            if ($type->code === 'loan_repayment') {
                throw new InvalidArgumentException('Loan repayments must be posted to a loan account.');
            }

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

    private function postLoanRepayment(Account $account, DrawerSession $session, TellerDrawer $drawer, TransactionType $type, float $amount, User $user, ?string $description, ?string $chequeNumber): Transaction
    {
        $loan = $account->client->loans()
            ->where('loan_account_id', $account->id)
            ->whereIn('status', ['active', 'in_arrears'])
            ->first();

        if (! $loan) {
            throw new InvalidArgumentException('No active loan is linked to this loan account.');
        }

        $drawer->increment('current_balance', $amount);
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
            'balance_after' => max(0, (float) $account->balance - $amount),
            'description' => $description,
        ]);

        app(LoanService::class)->repay($loan, $amount, $user, $transaction->id, 'cash', $transaction->reference);

        $loan->refresh();
        $account->update(['balance' => max(0, (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance)]);
        $transaction->update(['balance_after' => $account->balance]);

        DrawerMovement::create([
            'drawer_session_id' => $session->id,
            'user_id' => $user->id,
            'type' => 'cash_in',
            'amount' => $amount,
            'balance_after' => $drawer->current_balance,
            'reference' => $transaction->reference,
            'description' => $description ?: 'Loan repayment',
        ]);

        $this->auditLogger->record('transaction.loan_repayment_posted', $transaction, [
            'account_number' => $account->account_number,
            'amount' => $amount,
            'loan_number' => $loan->loan_number,
        ]);

        return $transaction->refresh();
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
                if ((float) ($journal['amount'] ?? 0) <= 0) {
                    continue;
                }

                $chartAccount = isset($journal['account_code'])
                    ? ChartAccount::query()->where('code', $journal['account_code'])->first()
                    : null;
                $payload = $journal;
                unset($payload['account_code']);

                JournalEntry::create($payload + [
                    'organization_id' => $account->organization_id,
                    'branch_id' => $account->branch_id,
                    'created_by' => $user->id,
                    'posted_by' => $user->id,
                    'journal_number' => 'JE'.now()->format('ymdHis').Str::upper(Str::random(4)),
                    'journal_date' => now()->toDateString(),
                    'description' => $description,
                    'status' => 'posted',
                    'posted_at' => now(),
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

    public function postGeneralLedgerCash(ChartAccount $chartAccount, DrawerSession $session, string $entryType, float $amount, User $user, ?string $description = null): JournalEntry
    {
        if (! in_array($entryType, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('General ledger posting must be debit or credit.');
        }

        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($chartAccount, $session, $entryType, $amount, $user, $description): JournalEntry {
            $chartAccount = ChartAccount::query()->whereKey($chartAccount->id)->lockForUpdate()->firstOrFail();
            $session = DrawerSession::query()->with('drawer')->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $drawer = $session->drawer()->lockForUpdate()->firstOrFail();

            if ($session->status !== 'open' || $drawer->status !== 'open') {
                throw new InvalidArgumentException('Teller drawer is closed.');
            }

            if ((int) $chartAccount->branch_id !== (int) $drawer->branch_id) {
                throw new InvalidArgumentException('Select a general ledger account for this branch.');
            }

            if (! $chartAccount->is_active) {
                throw new InvalidArgumentException('Selected general ledger account is inactive.');
            }

            if ($chartAccount->is_cash_account) {
                throw new InvalidArgumentException('Use a non-cash general ledger account.');
            }

            $financialControls = app(FinancialControlService::class);
            $businessDate = $financialControls->ensureFinancialDayOpen($user, (int) $drawer->branch_id, now());
            $financialControls->enforceTransactionLimit($user, $amount, $businessDate);

            if ($entryType === 'debit' && (float) $drawer->current_balance < $amount) {
                throw new InvalidArgumentException('Insufficient drawer cash.');
            }

            $entryType === 'debit'
                ? $drawer->decrement('current_balance', $amount)
                : $drawer->increment('current_balance', $amount);
            $drawer->refresh();

            $journal = JournalEntry::create([
                'organization_id' => $user->organization_id,
                'branch_id' => $drawer->branch_id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => 'TJ'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => now()->toDateString(),
                'description' => $description ?: 'Teller general ledger cash posting',
                'status' => 'posted',
                'posted_at' => now(),
                'chart_account_id' => $chartAccount->id,
                'reference' => $this->reference(),
                'account_name' => $chartAccount->name,
                'entry_type' => $entryType,
                'amount' => $amount,
                'memo' => $description,
            ]);

            $cashAccount = ChartAccount::query()->where('code', '1010')->first();
            $lines = $entryType === 'debit'
                ? [
                    [$chartAccount->id, $amount, 0, $description ?: $chartAccount->name],
                    [$cashAccount?->id, 0, $amount, 'Cash paid from teller drawer'],
                ]
                : [
                    [$cashAccount?->id, $amount, 0, 'Cash received into teller drawer'],
                    [$chartAccount->id, 0, $amount, $description ?: $chartAccount->name],
                ];

            foreach ($lines as [$chartAccountId, $debit, $credit, $narration]) {
                if ($chartAccountId) {
                    JournalLine::create([
                        'journal_entry_id' => $journal->id,
                        'chart_account_id' => $chartAccountId,
                        'debit' => $debit,
                        'credit' => $credit,
                        'narration' => $narration,
                    ]);
                }
            }

            DrawerMovement::create([
                'drawer_session_id' => $session->id,
                'user_id' => $user->id,
                'type' => $entryType === 'debit' ? 'cash_out' : 'cash_in',
                'amount' => $amount,
                'balance_after' => $drawer->current_balance,
                'reference' => $journal->reference,
                'description' => $description ?: $chartAccount->name,
            ]);

            $this->auditLogger->record('transaction.general_ledger_cash_posted', $journal, [
                'chart_account' => $chartAccount->code,
                'entry_type' => $entryType,
                'amount' => $amount,
            ]);

            return $journal->refresh();
        });
    }

    private function reference(): string
    {
        return 'DF'.now()->format('ymdHis').Str::upper(Str::random(5));
    }
}
