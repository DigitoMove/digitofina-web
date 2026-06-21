<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\CurrencyDenomination;
use App\Support\TransactionTimestamp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TransactionPostingService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InAppNotificationService $notifications,
        private BranchGeneralLedgerService $generalLedger,
    ) {}

    public function postCash(Account $account, DrawerSession $session, TransactionType $type, float $amount, User $user, ?string $description = null, ?string $chequeNumber = null, ?CarbonInterface $postedAt = null, float $charges = 0): Transaction
    {
        $postedAt = TransactionTimestamp::forBusinessDate($postedAt);
        $this->assertCharges($charges);

        return DB::transaction(function () use ($account, $session, $type, $amount, $user, $description, $chequeNumber, $postedAt, $charges) {
            $account = Account::query()->with(['accountType', 'client.loans'])->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $session = DrawerSession::query()->with('drawer')->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $drawer = $session->drawer()->with('branch')->lockForUpdate()->firstOrFail();

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
            $businessDate = $financialControls->ensureFinancialDayOpen($user, (int) $drawer->branch_id, $postedAt);
            $financialControls->enforceTransactionLimit($user, $amount + $charges, $businessDate);

            $direction = $type->direction;

            if ($account->accountType?->category === 'loan' && $type->code === 'loan_repayment') {
                return $this->postLoanRepayment($account, $session, $drawer, $type, $amount, $user, $description, $chequeNumber, $postedAt, $charges);
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

                $cashImpact = $amount + $charges;

                if ((float) $drawer->current_balance < $cashImpact) {
                    throw new InvalidArgumentException('Insufficient drawer cash.');
                }

                $account->decrement('balance', $amount);
                $drawer->decrement('current_balance', $cashImpact);
                $movementType = 'cash_out';
            } else {
                if ($charges > $amount) {
                    throw new InvalidArgumentException('Charges cannot exceed money received into the drawer.');
                }

                $cashImpact = $amount - $charges;
                $account->increment('balance', $amount);
                $drawer->increment('current_balance', $cashImpact);
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
                'charges' => $charges,
                'balance_after' => $account->balance,
                'description' => $description,
                'posted_at' => $postedAt,
            ]);

            DrawerMovement::create([
                'drawer_session_id' => $session->id,
                'user_id' => $user->id,
                'type' => $movementType,
                'amount' => $cashImpact,
                'balance_after' => $drawer->current_balance,
                'reference' => $transaction->reference,
                'description' => $description,
            ]);

            if ($charges > 0) {
                $this->postChargeJournal($transaction, $drawer, $charges, $user, $postedAt, $description);
            }

            $this->auditLogger->record('transaction.posted', $transaction, [
                'account_number' => $account->account_number,
                'amount' => $amount,
                'type' => $type->code,
            ]);
            $this->notifications->notifyAccountClient(
                $account,
                'Account transaction posted',
                number_format($amount, 2).' was '.(in_array($direction, ['debit', 'withdrawal'], true) ? 'withdrawn from' : 'deposited into')." account {$account->account_number}.",
                route('accounts.show', $account),
                'account',
                'info',
                ['transaction_id' => $transaction->id, 'reference' => $transaction->reference],
            );

            return $transaction;
        });
    }

    private function postLoanRepayment(Account $account, DrawerSession $session, TellerDrawer $drawer, TransactionType $type, float $amount, User $user, ?string $description, ?string $chequeNumber, CarbonInterface $postedAt, float $charges): Transaction
    {
        $loan = $account->client->loans()
            ->where('loan_account_id', $account->id)
            ->whereIn('status', ['active', 'in_arrears'])
            ->first();

        if (! $loan) {
            throw new InvalidArgumentException('No active loan is linked to this loan account.');
        }

        if ($charges > $amount) {
            throw new InvalidArgumentException('Charges cannot exceed money received into the drawer.');
        }

        $cashImpact = $amount - $charges;
        $drawer->increment('current_balance', $cashImpact);
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
            'charges' => $charges,
            'balance_after' => max(0, (float) $account->balance - $amount),
            'description' => $description,
            'posted_at' => $postedAt,
        ]);

        app(LoanService::class)->repay($loan, $amount, $user, $transaction->id, 'cash', $transaction->reference, $postedAt);

        $loan->refresh();
        $account->update(['balance' => max(0, (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance)]);
        $transaction->update(['balance_after' => $account->balance]);

        DrawerMovement::create([
            'drawer_session_id' => $session->id,
            'user_id' => $user->id,
            'type' => 'cash_in',
            'amount' => $cashImpact,
            'balance_after' => $drawer->current_balance,
            'reference' => $transaction->reference,
            'description' => $description ?: 'Loan repayment',
        ]);

        if ($charges > 0) {
            $this->postChargeJournal($transaction, $drawer, $charges, $user, $postedAt, $description);
        }

        $this->auditLogger->record('transaction.loan_repayment_posted', $transaction, [
            'account_number' => $account->account_number,
            'amount' => $amount,
            'loan_number' => $loan->loan_number,
        ]);

        return $transaction->refresh();
    }

    public function postNonCashCredit(Account $account, TransactionType $type, float $amount, User $user, string $description, array $journals = [], ?CarbonInterface $postedAt = null): Transaction
    {
        $postedAt = TransactionTimestamp::forBusinessDate($postedAt);

        return DB::transaction(function () use ($account, $type, $amount, $user, $description, $journals, $postedAt) {
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
                'posted_at' => $postedAt,
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
                    'journal_date' => $postedAt->toDateString(),
                    'description' => $description,
                    'status' => 'posted',
                    'posted_at' => $postedAt,
                    'chart_account_id' => $chartAccount?->id,
                    'account_name' => $journal['account_name'] ?? $chartAccount?->name,
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                ]);
            }

            $this->auditLogger->record('transaction.non_cash_credit_posted', $transaction, ['amount' => $amount]);
            $this->notifications->notifyAccountClient(
                $account->refresh()->loadMissing('client.user'),
                'Account credited',
                number_format($amount, 2)." was credited to account {$account->account_number}.",
                route('accounts.show', $account),
                'account',
                'success',
                ['transaction_id' => $transaction->id, 'reference' => $transaction->reference],
            );

            return $transaction;
        });
    }

    public function postNonCash(Account $account, TransactionType $type, ChartAccount $offsetAccount, float $amount, User $user, string $description, ?CarbonInterface $postedAt = null): Transaction
    {
        $postedAt = TransactionTimestamp::forBusinessDate($postedAt);
        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($account, $type, $offsetAccount, $amount, $user, $description, $postedAt): Transaction {
            $account = Account::query()->with(['accountType', 'client.loans'])->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $offsetAccount = ChartAccount::query()->whereKey($offsetAccount->id)->lockForUpdate()->firstOrFail();

            if ($account->status !== 'active') {
                throw new InvalidArgumentException('Account is not active.');
            }

            if (! $offsetAccount->is_active || $offsetAccount->is_cash_account) {
                throw new InvalidArgumentException('Select an active non-cash general ledger account.');
            }

            if ((int) $account->organization_id !== (int) $offsetAccount->organization_id
                || ($offsetAccount->branch_id !== null && (int) $account->branch_id !== (int) $offsetAccount->branch_id)) {
                throw new InvalidArgumentException('The offset ledger must be organization-level or belong to the same branch.');
            }

            app(FinancialControlService::class)->ensureFinancialDayOpen($user, (int) $account->branch_id, $postedAt);

            if ($account->accountType?->category === 'loan') {
                if ($type->code !== 'loan_repayment') {
                    throw new InvalidArgumentException('Loan accounts only accept non-cash loan repayments.');
                }

                if ($offsetAccount->type !== 'asset' || $offsetAccount->normal_balance !== 'debit') {
                    throw new InvalidArgumentException('Use a debit-normal asset ledger, such as Merchant Code or Mobile Money Clearing, for non-cash loan repayments.');
                }

                return $this->postNonCashLoanRepayment($account, $type, $offsetAccount, $amount, $user, $description, $postedAt);
            }

            $direction = $type->direction;

            if (in_array($direction, ['debit', 'withdrawal'], true)) {
                if ((float) $account->balance < $amount) {
                    throw new InvalidArgumentException('Insufficient account balance.');
                }

                $account->decrement('balance', $amount);
                $offsetEntryType = 'credit';
            } else {
                $account->increment('balance', $amount);
                $offsetEntryType = 'debit';
            }

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
                'posted_at' => $postedAt,
            ]);

            JournalEntry::create([
                'organization_id' => $account->organization_id,
                'branch_id' => $account->branch_id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => 'NC'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => $postedAt->toDateString(),
                'description' => $description,
                'status' => 'posted',
                'posted_at' => $postedAt,
                'chart_account_id' => $offsetAccount->id,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'account_name' => $offsetAccount->name,
                'entry_type' => $offsetEntryType,
                'amount' => $amount,
                'memo' => $description,
            ]);

            $this->auditLogger->record('transaction.non_cash_posted', $transaction, [
                'account_number' => $account->account_number,
                'offset_account' => $offsetAccount->code,
                'amount' => $amount,
                'type' => $type->code,
            ]);
            $this->notifications->notifyAccountClient(
                $account,
                'Non-cash transaction posted',
                number_format($amount, 2).' was '.(in_array($direction, ['debit', 'withdrawal'], true) ? 'debited from' : 'credited to')." account {$account->account_number}.",
                route('accounts.show', $account),
                'account',
                'info',
                ['transaction_id' => $transaction->id, 'reference' => $transaction->reference],
            );

            return $transaction;
        });
    }

    private function postNonCashLoanRepayment(Account $account, TransactionType $type, ChartAccount $offsetAccount, float $amount, User $user, string $description, CarbonInterface $postedAt): Transaction
    {
        $loan = $account->client->loans()
            ->where('loan_account_id', $account->id)
            ->whereIn('status', ['active', 'in_arrears'])
            ->first();

        if (! $loan) {
            throw new InvalidArgumentException('No active loan is linked to this loan account.');
        }

        $transaction = Transaction::create([
            'account_id' => $account->id,
            'transaction_type_id' => $type->id,
            'branch_id' => $account->branch_id,
            'user_id' => $user->id,
            'reference' => $this->reference(),
            'amount' => $amount,
            'balance_after' => max(0, (float) $account->balance - $amount),
            'description' => $description,
            'posted_at' => $postedAt,
        ]);

        app(LoanService::class)->repay($loan, $amount, $user, $transaction->id, 'non_cash', $transaction->reference, $postedAt, $offsetAccount->code, $description);

        $loan->refresh();
        $account->update(['balance' => max(0, (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance)]);
        $transaction->update(['balance_after' => $account->balance]);

        $this->auditLogger->record('transaction.non_cash_loan_repayment_posted', $transaction, [
            'account_number' => $account->account_number,
            'amount' => $amount,
            'loan_number' => $loan->loan_number,
            'offset_account' => $offsetAccount->code,
        ]);

        return $transaction->refresh();
    }

    public function postGeneralLedgerCash(ChartAccount $chartAccount, DrawerSession $session, string $entryType, float $amount, User $user, ?string $description = null, ?CarbonInterface $postedAt = null, float $charges = 0): JournalEntry
    {
        $postedAt = TransactionTimestamp::forBusinessDate($postedAt);

        if (! in_array($entryType, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('General ledger posting must be debit or credit.');
        }

        CurrencyDenomination::assertValid($amount);
        $this->assertCharges($charges);

        return DB::transaction(function () use ($chartAccount, $session, $entryType, $amount, $user, $description, $postedAt, $charges): JournalEntry {
            $chartAccount = ChartAccount::query()->whereKey($chartAccount->id)->lockForUpdate()->firstOrFail();
            $session = DrawerSession::query()->with('drawer')->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $drawer = $session->drawer()->with('branch')->lockForUpdate()->firstOrFail();

            if ($session->status !== 'open' || $drawer->status !== 'open') {
                throw new InvalidArgumentException('Teller drawer is closed.');
            }

            if ((int) $chartAccount->organization_id !== (int) $drawer->branch->organization_id
                || ($chartAccount->branch_id !== null && (int) $chartAccount->branch_id !== (int) $drawer->branch_id)) {
                throw new InvalidArgumentException('Select an organization-level ledger or a ledger for this branch.');
            }

            if (! $chartAccount->is_active) {
                throw new InvalidArgumentException('Selected general ledger account is inactive.');
            }

            if ($chartAccount->drawer_direction === 'none') {
                throw new InvalidArgumentException('This ledger account is configured for non-cash use only.');
            }

            if ($chartAccount->is_cash_account) {
                throw new InvalidArgumentException('Use a non-cash general ledger account.');
            }

            if ($chartAccount->drawer_direction === 'cash_in' && $entryType !== 'credit') {
                throw new InvalidArgumentException('This ledger account is configured to add money to the drawer.');
            }

            if ($chartAccount->drawer_direction === 'cash_out' && $entryType !== 'debit') {
                throw new InvalidArgumentException('This ledger account is configured to take money from the drawer.');
            }

            $financialControls = app(FinancialControlService::class);
            $businessDate = $financialControls->ensureFinancialDayOpen($user, (int) $drawer->branch_id, $postedAt);
            $financialControls->enforceTransactionLimit($user, $amount + $charges, $businessDate);

            if ($entryType === 'credit' && $charges > $amount) {
                throw new InvalidArgumentException('Charges cannot exceed money received into the drawer.');
            }

            $cashImpact = $entryType === 'debit' ? $amount + $charges : $amount - $charges;

            if ($entryType === 'debit' && (float) $drawer->current_balance < $cashImpact) {
                throw new InvalidArgumentException('Insufficient drawer cash.');
            }

            $entryType === 'debit'
                ? $drawer->decrement('current_balance', $cashImpact)
                : $drawer->increment('current_balance', $cashImpact);
            $drawer->refresh();

            $type = TransactionType::query()->firstOrCreate(
                ['code' => $entryType === 'debit' ? 'drawer_gl_cash_out' : 'drawer_gl_cash_in'],
                [
                    'name' => $entryType === 'debit' ? 'Drawer GL Cash Out' : 'Drawer GL Cash In',
                    'direction' => $entryType === 'debit' ? 'debit' : 'credit',
                    'affects_cash_drawer' => true,
                    'is_active' => true,
                ],
            );
            $transaction = Transaction::create([
                'account_id' => null,
                'transaction_type_id' => $type->id,
                'drawer_session_id' => $session->id,
                'branch_id' => $drawer->branch_id,
                'user_id' => $user->id,
                'reference' => $this->reference(),
                'amount' => $amount,
                'charges' => $charges,
                'balance_after' => $drawer->current_balance,
                'currency' => $drawer->currency,
                'description' => $description ?: 'Teller general ledger cash posting for '.$chartAccount->name,
                'status' => 'posted',
                'posted_at' => $postedAt,
            ]);

            $journal = JournalEntry::create([
                'organization_id' => $drawer->branch->organization_id,
                'branch_id' => $drawer->branch_id,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'journal_number' => 'TJ'.now()->format('ymdHis').Str::upper(Str::random(4)),
                'journal_date' => $postedAt->toDateString(),
                'description' => $description ?: 'Teller general ledger cash posting',
                'status' => 'posted',
                'posted_at' => $postedAt,
                'chart_account_id' => $chartAccount->id,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'account_name' => $chartAccount->name,
                'entry_type' => $entryType,
                'amount' => $amount,
                'memo' => $description,
            ]);

            $cashAccount = $this->cashAccount($drawer);
            $chargeAccount = $charges > 0 ? $this->generalLedger->transactionChargesExpense($drawer->branch) : null;
            $lines = $entryType === 'debit'
                ? [
                    [$chartAccount->id, $amount, 0, $description ?: $chartAccount->name],
                    [$cashAccount->id, 0, $amount, 'Cash paid from teller drawer'],
                ]
                : [
                    [$cashAccount->id, $amount, 0, 'Cash received into teller drawer'],
                    [$chartAccount->id, 0, $amount, $description ?: $chartAccount->name],
                ];

            if ($chargeAccount) {
                $lines[] = [$chargeAccount->id, $charges, 0, 'Transaction charges'];
                $lines[] = [$cashAccount->id, 0, $charges, 'Charges paid from teller drawer'];
            }

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
                'amount' => $cashImpact,
                'balance_after' => $drawer->current_balance,
                'reference' => $transaction->reference,
                'description' => $description ?: $chartAccount->name,
            ]);

            $this->auditLogger->record('transaction.general_ledger_cash_posted', $journal, [
                'chart_account' => $chartAccount->code,
                'entry_type' => $entryType,
                'amount' => $amount,
                'charges' => $charges,
            ]);
            $this->notifications->notifyStaffByPermission(
                ['manage-drawers', 'approve-reversals', 'view-reports'],
                $drawer->branch_id,
                $user->organization_id,
                'Drawer ledger posting',
                number_format($amount, 2).' was '.($entryType === 'debit' ? 'paid from' : 'received into').' the drawer for '.$chartAccount->name.'.',
                route('teller.index'),
                'drawer',
                'info',
                ['journal_entry_id' => $journal->id, 'reference' => $journal->reference, 'actor_id' => $user->id],
            );

            return $journal->refresh();
        });
    }

    private function postChargeJournal(Transaction $transaction, TellerDrawer $drawer, float $charges, User $user, CarbonInterface $postedAt, ?string $description): void
    {
        $chargeAccount = $this->generalLedger->transactionChargesExpense($drawer->branch);
        $cashAccount = $this->cashAccount($drawer);
        $journal = JournalEntry::create([
            'organization_id' => $drawer->branch->organization_id,
            'branch_id' => $drawer->branch_id,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'journal_number' => 'TC'.now()->format('ymdHis').Str::upper(Str::random(4)),
            'journal_date' => $postedAt->toDateString(),
            'description' => 'Transaction charges for '.$transaction->reference,
            'status' => 'posted',
            'posted_at' => $postedAt,
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'account_name' => $chargeAccount->name,
            'entry_type' => 'debit',
            'amount' => $charges,
            'memo' => $description,
        ]);

        foreach ([
            [$chargeAccount->id, $charges, 0],
            [$cashAccount->id, 0, $charges],
        ] as [$accountId, $debit, $credit]) {
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'chart_account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => 'Transaction charges',
            ]);
        }
    }

    private function cashAccount(TellerDrawer $drawer): ChartAccount
    {
        $cashAccount = ChartAccount::query()
            ->withoutGlobalScope('tenant')
            ->where('is_cash_account', true)
            ->where('is_active', true)
            ->where(function ($query) use ($drawer): void {
                $query->where('branch_id', $drawer->branch_id)->orWhereNull('branch_id');
            })
            ->orderByRaw('branch_id is null')
            ->first();

        if (! $cashAccount) {
            throw new InvalidArgumentException('Configure an active cash ledger before posting drawer transactions.');
        }

        return $cashAccount;
    }

    private function assertCharges(float $charges): void
    {
        if ($charges < 0) {
            throw new InvalidArgumentException('Charges cannot be negative.');
        }

        if ($charges > 0) {
            CurrencyDenomination::assertValid($charges, 'Charges');
        }
    }

    private function reference(): string
    {
        return 'DF'.now()->format('ymdHis').Str::upper(Str::random(5));
    }
}
