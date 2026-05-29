<?php

namespace App\Services;

use App\Models\Account;
use App\Models\DrawerMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LoanRepayment;
use App\Models\MakerCheckerRequest;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionDeletionService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @return array{reference: string, amount: float, drawer_direction: string, drawer_balance_after: float}
     */
    public function deleteByReference(string $reference, User $actor): array
    {
        return DB::transaction(function () use ($reference, $actor): array {
            $transaction = Transaction::query()
                ->with(['account.accountType', 'drawerSession.drawer.branch', 'journalEntries.lines', 'type'])
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new InvalidArgumentException('No transaction was found with that reference.');
            }

            $organizationId = $transaction->drawerSession?->drawer?->branch?->organization_id;

            if (! $actor->canAccessBranch($transaction->branch_id, $organizationId)) {
                throw new InvalidArgumentException('You are not allowed to delete this transaction.');
            }

            if (! $this->canDeleteTransactions($actor)) {
                throw new InvalidArgumentException('You are not allowed to delete transactions.');
            }

            if ($transaction->status !== 'posted') {
                throw new InvalidArgumentException('Only posted transactions can be deleted by reference.');
            }

            if ($transaction->reversed_by !== null || $transaction->reversal_of_id !== null) {
                throw new InvalidArgumentException('Reversed or reversal transactions cannot be deleted by reference.');
            }

            if (LoanRepayment::query()->where('transaction_id', $transaction->id)->exists()) {
                throw new InvalidArgumentException('Loan repayment transactions must be reversed through the reversal workflow before deletion.');
            }

            $movement = DrawerMovement::query()
                ->where('drawer_session_id', $transaction->drawer_session_id)
                ->where('reference', $transaction->reference)
                ->whereIn('type', ['cash_in', 'cash_out'])
                ->lockForUpdate()
                ->latest()
                ->first();

            if (! $movement || ! $transaction->drawerSession?->drawer) {
                throw new InvalidArgumentException('Only drawer-linked transactions with a cash movement can be deleted by reference.');
            }

            $drawer = TellerDrawer::query()
                ->whereKey($transaction->drawerSession->drawer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (float) $movement->amount;
            $restoreType = $movement->type === 'cash_out' ? 'cash_in' : 'cash_out';

            if ($restoreType === 'cash_out' && (float) $drawer->current_balance < $amount) {
                throw new InvalidArgumentException('The drawer does not have enough cash to remove the original money-in amount.');
            }

            $this->reverseAccountBalance($transaction);

            $restoreType === 'cash_out'
                ? $drawer->decrement('current_balance', $amount)
                : $drawer->increment('current_balance', $amount);

            $drawer->refresh();

            DrawerMovement::query()
                ->where('drawer_session_id', $transaction->drawer_session_id)
                ->where('reference', $transaction->reference)
                ->whereIn('type', ['cash_in', 'cash_out'])
                ->delete();

            DrawerMovement::create([
                'drawer_session_id' => $transaction->drawer_session_id,
                'user_id' => $transaction->user_id,
                'type' => $restoreType,
                'amount' => $amount,
                'balance_after' => $drawer->current_balance,
                'reference' => $transaction->reference.'-DELETE',
                'description' => 'Transaction deleted by '.$actor->name.'. Drawer cash adjusted.',
            ]);

            foreach ($transaction->journalEntries as $journalEntry) {
                $journalEntry->lines()->delete();
            }

            JournalLine::query()
                ->whereIn('journal_entry_id', JournalEntry::query()->where('transaction_id', $transaction->id)->select('id'))
                ->delete();
            JournalEntry::query()->where('transaction_id', $transaction->id)->delete();

            MakerCheckerRequest::query()
                ->where('subject_type', $transaction->getMorphClass())
                ->where('subject_id', $transaction->id)
                ->delete();

            $payload = [
                'reference' => $transaction->reference,
                'transaction_id' => $transaction->id,
                'amount' => (float) $transaction->amount,
                'drawer_direction' => $restoreType,
                'drawer_id' => $drawer->id,
                'drawer_balance_after' => (float) $drawer->current_balance,
                'cashier_id' => $transaction->user_id,
            ];

            $this->auditLogger->record('transaction.deleted_by_reference', $transaction, $payload, actor: $actor);
            $transaction->delete();

            return [
                'reference' => (string) $payload['reference'],
                'amount' => (float) $payload['amount'],
                'drawer_direction' => (string) $payload['drawer_direction'],
                'drawer_balance_after' => (float) $payload['drawer_balance_after'],
            ];
        });
    }

    private function reverseAccountBalance(Transaction $transaction): void
    {
        if (! $transaction->account_id) {
            return;
        }

        $account = Account::query()
            ->whereKey($transaction->account_id)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            return;
        }

        $amount = (float) $transaction->amount;

        if (in_array($transaction->type?->direction, ['debit', 'withdrawal'], true)) {
            $account->increment('balance', $amount);

            return;
        }

        if ((float) $account->balance < $amount) {
            throw new InvalidArgumentException('The account balance is too low to delete this transaction.');
        }

        $account->decrement('balance', $amount);
    }

    private function canDeleteTransactions(User $actor): bool
    {
        return $actor->hasRole('super-admin')
            || $actor->hasRole('organization-admin')
            || $actor->canPerform('manage-drawers')
            || $actor->canPerform('approve-reversals');
    }
}
