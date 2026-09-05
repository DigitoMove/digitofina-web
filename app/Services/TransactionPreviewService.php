<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\MakerCheckerRequest;
use App\Models\SavingsAccount;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\ApiSecurity;
use InvalidArgumentException;

class TransactionPreviewService
{
    public function __construct(private TransactionPostingService $posting, private LedgerAccountStatementService $ledgers, private LoanService $loans) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(array $data, User $user): array
    {
        abort_if($user->isMember() || (! $user->organization_id && ! $user->hasGlobalOrganizationAccess()), 403);
        if ($data['kind'] === 'reversal') {
            return $this->reversal($data, $user);
        }
        $amount = (float) $data['amount'];
        $charges = (float) ($data['charges'] ?? 0);
        $rows = [];
        $notes = ['Read-only estimate using current balances. Nothing has been posted. Posting revalidates permissions, limits and balances; another transaction can change these figures.'];
        if (str_starts_with($data['kind'], 'savings-')) {
            $this->authorizeModule($user, 'savings');
            abort_unless($user->canPerform('post-transactions'), 403);
            $service = app(SavingsTransactionService::class);
            $account = $service->resolveTransactionAccount((int) ($data['account_id'] ?? $data['from_savings_account_id'] ?? 0), $user);
            $this->active($account->status);
            $before = (float) $account->current_balance;
            if ($data['kind'] === 'savings-transfer') {
                $to = $service->resolveTransactionAccount((int) ($data['to_savings_account_id'] ?? 0), $user);
                $this->active($to->status);
                if ($to->is($account)) {
                    throw new InvalidArgumentException('Choose two different savings accounts.');
                }
                $this->ensureSavingsFunds($account, $amount);
                $rows[] = $this->row('From '.$account->account_number, $before, -$amount);
                $rows[] = $this->row('To '.$to->account_number, (float) $to->current_balance, $amount);
                $charges = 0;
                $notes[] = 'Internal savings transfer: no teller drawer cash movement.';
            } else {
                $deposit = $data['kind'] === 'savings-deposit';
                $charges = (float) ($deposit ? $account->product->deposit_charge : $account->product->withdrawal_charge);
                if ($deposit && $charges >= $amount) {
                    throw new InvalidArgumentException('Deposit amount must exceed product charges.');
                }
                $delta = $deposit ? $amount - $charges : -($amount + $charges);
                if (! $deposit) {
                    $this->ensureSavingsFunds($account, -$delta);
                }
                $rows[] = $this->row('Savings '.$account->account_number, $before, $delta);
                $rows[] = $this->row('Available savings', (float) $account->available_balance, max(0, $before + $delta - (float) $account->locked_amount) - (float) $account->available_balance);
                $notes[] = 'Product charges are included in the account movement. This savings-product workflow posts the cash and savings-liability journal; it does not update a teller drawer session.';
                $notes[] = 'Journal: cash '.($deposit ? 'debit' : 'credit').' '.number_format($amount, 2).'; savings liability '.($deposit ? 'credit' : 'debit').' '.number_format(abs($delta), 2).'; fee income credit '.number_format($charges, 2).'.';
            }
            return $this->document($rows, $notes, $amount, $charges, $user);
        }

        $cash = $data['kind'] === 'teller';
        $this->authorizeModule($user, 'teller');
        $session = $cash ? DrawerSession::query()->with('drawer')->where('user_id', $user->id)->where('status', 'open')->latest()->first() : null;
        if ($cash && (! $session?->drawer || $session->drawer->status !== 'open')) {
            throw new InvalidArgumentException('Open your teller drawer before reviewing this cash transaction.');
        }

        if ($cash && ($data['account_scope'] ?? '') === 'general_ledger') {
            abort_unless($user->canPerform('post-transactions'), 403);
            $ledger = $this->ledger((int) ($data['chart_account_id'] ?? 0), $user, (int) $session->drawer->branch_id, $session->drawer->organization_id);
            $entry = match ($ledger->drawer_direction) {'cash_in' => 'credit', 'cash_out' => 'debit', default => $data['gl_entry_type'] ?? null};
            if (! in_array($entry, ['debit', 'credit'], true) || $ledger->drawer_direction === 'none') {
                throw new InvalidArgumentException('Choose a valid drawer money direction for this ledger.');
            }
            $mode = LedgerChargeService::normalizeMode($data['charge_mode'] ?? null);
            if ($mode === LedgerChargeService::MODE_DEDUCT_FROM_AMOUNT && $charges > $amount) {
                throw new InvalidArgumentException('Charges cannot exceed the amount when deducted.');
            }
            $movement = $this->posting->ledgerAmount($entry, $amount, $charges, $mode);
            $cashDelta = ($entry === 'credit' ? 1 : -1) * $this->posting->cashImpact($entry, $amount, $charges, $mode);
            $rows[] = $this->ledgerRow($ledger, (int) $session->drawer->branch_id, $entry, $movement);
        } else {
            $accountQuery = Account::query()->withoutGlobalScope('tenant')->with(['accountType', 'client']);
            $organizationId = app(TenantContext::class)->id() ?: $user->organization_id;
            $accountQuery->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId));
            if (! ApiSecurity::hasGlobalBranchAccess($user)) {
                $accountQuery->where(function ($query) use ($user, $cash): void {
                    $query->where('branch_id', $user->branch_id);
                    if ($cash && $user->canPerform('transact-savings-across-branches')) {
                        $query->orWhereHas('accountType', fn ($type) => $type->where('category', 'savings'));
                    }
                });
            }
            $account = isset($data['account_id']) ? $accountQuery->findOrFail($data['account_id']) : $accountQuery->where('account_number', $data['account_number'] ?? '')->firstOrFail();
            $this->active($account->status);
            if ($cash && ((int) $account->organization_id !== (int) $session->drawer->organization_id || ((int) $account->branch_id !== (int) $session->drawer->branch_id && ($account->accountType?->category !== 'savings' || ! $user->canPerform('transact-savings-across-branches'))))) {
                throw new InvalidArgumentException('The selected account cannot be posted through this drawer.');
            }
            $type = TransactionType::findOrFail($data['transaction_type_id'] ?? 0);
            abort_unless($user->canPerform($type->code === 'loan_repayment' ? 'post-repayments' : 'post-transactions'), 403);
            $debit = in_array($type->direction, ['debit', 'withdrawal'], true);
            if ($account->accountType?->category === 'loan') {
                $this->authorizeModule($user, 'loans');
                if ($type->code !== 'loan_repayment') {
                    throw new InvalidArgumentException('Loan accounts only accept repayments.');
                }
                $loan = Loan::query()->where('loan_account_id', $account->id)->whereIn('status', ['active', 'in_arrears'])->firstOrFail();
                $rows = $this->loanRows($loan, $amount);
                $notes[] = 'Allocation follows installments by due date: penalties, fees, interest, then principal within each installment. Principal reduces debt; it is not income.';
                $debit = false;
            } else {
                if ($type->code === 'loan_repayment') {
                    throw new InvalidArgumentException('Choose a loan account for a repayment.');
                }
                if ($debit && (float) $account->balance < $amount) {
                    throw new InvalidArgumentException('Insufficient account balance.');
                }
                $rows[] = $this->row('Account '.$account->account_number, (float) $account->balance, $debit ? -$amount : $amount);
            }
            if ($cash) {
                if (! $debit && $charges > $amount) {
                    throw new InvalidArgumentException('Charges cannot exceed cash received.');
                }
                $cashDelta = $debit ? -($amount + $charges) : $amount - $charges;
                $notes[] = 'Teller charges affect drawer cash, not the entered client-account or repayment amount.';
            } else {
                $ledger = $this->ledger((int) ($data['chart_account_id'] ?? 0), $user, (int) $account->branch_id, $account->organization_id);
                if ($account->accountType?->category === 'loan' && ($ledger->type !== 'asset' || $ledger->normal_balance !== 'debit')) {
                    throw new InvalidArgumentException('Loan repayments require a debit-normal asset payment ledger.');
                }
                $entry = $debit ? 'credit' : 'debit';
                $movement = $this->posting->nonCashLedgerAmount($entry, $amount, $charges, LedgerChargeService::normalizeMode($data['charge_mode'] ?? null));
                if ($movement <= 0) {
                    throw new InvalidArgumentException('Charges leave no positive ledger movement.');
                }
                $rows[] = $this->ledgerRow($ledger, (int) $account->branch_id, $entry, $movement);
                $notes[] = 'Non-cash payment: teller drawers remain unchanged. Ledger charges are booked separately as an expense.';
            }
        }
        if ($cash) {
            if ((float) $session->drawer->current_balance + $cashDelta < 0) {
                throw new InvalidArgumentException('Insufficient drawer cash for the amount and charges.');
            }
            $rows[] = $this->row('Drawer '.$session->drawer->code, (float) $session->drawer->current_balance, $cashDelta);
        }
        return $this->document($rows, $notes, $amount, $charges, $user);
    }

    /** @return array<int, array<string, mixed>> */
    private function loanRows(Loan $loan, float $amount): array
    {
        $allocation = $this->loans->previewSchedulePayments($loan, $amount);
        if (round($allocation['applied'], 2) !== round($amount, 2)) {
            throw new InvalidArgumentException('Only '.number_format($allocation['applied'], 2).' can be allocated to outstanding installments. Reduce the amount or reconcile the loan schedule.');
        }
        $rows = [];
        foreach (['penalty' => 'Penalties', 'fees' => 'Fees', 'interest' => 'Interest', 'principal' => 'Principal'] as $key => $label) {
            $rows[] = $this->row($label.' outstanding', (float) $loan->{$key.'_balance'}, -min((float) $loan->{$key.'_balance'}, $allocation['totals'][$key]));
        }
        $rows[] = $this->row('Total loan outstanding', array_sum(array_column($rows, 'before')), array_sum(array_column($rows, 'change')));
        return $rows;
    }

    private function ledger(int $id, User $user, int $branchId, ?int $organizationId): ChartAccount
    {
        $ledger = ChartAccount::query()->where('organization_id', $organizationId)->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))->findOrFail($id);
        ApiSecurity::assertTenantAccess($user, $ledger);
        if (! $ledger->is_active || $ledger->is_cash_account) {
            throw new InvalidArgumentException('Select an active non-cash ledger.');
        }
        return $ledger;
    }

    /** @return array<string, mixed> */
    private function ledgerRow(ChartAccount $ledger, int $branchId, string $entry, float $amount): array
    {
        return $this->row('Ledger '.$ledger->code.' · '.$ledger->name.' (branch balance)', $this->ledgers->currentBalance($ledger, $branchId), $ledger->normal_balance === $entry ? $amount : -$amount);
    }

    private function ensureSavingsFunds(SavingsAccount $account, float $amount): void
    {
        if ((! $account->product->allows_overdraft && (float) $account->current_balance - $amount < (float) $account->product->minimum_balance) || $amount > (float) $account->current_balance - (float) $account->locked_amount) {
            throw new InvalidArgumentException('This movement exceeds unlocked funds or breaches the minimum savings balance.');
        }
    }

    private function active(string $status): void
    {
        if ($status !== 'active') {
            throw new InvalidArgumentException('The selected account is not active.');
        }
    }

    private function authorizeModule(User $user, string $module): void
    {
        abort_unless($user->hasRole('super-admin') || app(ModuleService::class)->isEnabled($user->organization, $module), 403);
    }

    /** @return array{label:string,before:float,change:float,after:float} */
    private function row(string $label, float $before, float $change): array
    {
        return ['label' => $label, 'before' => round($before, 2), 'change' => round($change, 2), 'after' => round($before + $change, 2)];
    }

    /** @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $notes
     * @return array<string, mixed>
     */
    private function document(array $rows, array $notes, float $amount, float $charges, User $user): array
    {
        return ['currency' => $user->organization?->currency ?: 'UGX', 'amount' => $amount, 'charges' => $charges, 'effects' => $rows, 'notes' => $notes, 'generated_at' => now()->toIso8601String()];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function reversal(array $data, User $user): array
    {
        abort_unless($user->canPerform('approve-reversals') || $user->canPerform('override-maker-checker'), 403);
        $approval = MakerCheckerRequest::findOrFail($data['approval_id']);
        ApiSecurity::assertTenantAccess($user, $approval);
        if ($approval->action !== 'transaction_reversal' || $approval->status !== 'pending') {
            throw new InvalidArgumentException('This is not a pending transaction reversal.');
        }
        if ($approval->requested_by === $user->id && ! $user->canPerform('override-maker-checker')) {
            throw new InvalidArgumentException('The maker cannot approve their own reversal.');
        }
        $original = Transaction::with(['account', 'type', 'originalTransaction'])->findOrFail($approval->subject_id);
        ApiSecurity::assertTenantAccess($user, $original);
        if ($original->status !== 'posted' || $original->reversed_by || $original->reversed_at) {
            throw new InvalidArgumentException('This transaction is already reversed or is not posted.');
        }
        $rows = [];
        $notes = ['Reversal of '.$original->reference.'. Current balances are shown; later transactions are preserved. Approval rechecks the transaction and available cash.'];
        $repayment = LoanRepayment::with('loan')->where('transaction_id', $original->id)->first();
        if ($repayment) {
            foreach (['principal', 'interest', 'fees', 'penalty'] as $part) {
                $rows[] = $this->row(ucfirst($part).' outstanding', (float) $repayment->loan->{$part.'_balance'}, (float) $repayment->{$part.'_paid'});
            }
            $notes[] = 'The repayment allocations will be undone and the loan status recalculated.';
        } elseif ($original->account) {
            $delta = in_array($original->type?->direction, ['debit', 'withdrawal'], true) ? (float) $original->amount : -(float) $original->amount;
            if ((float) $original->account->balance + $delta < 0) {
                throw new InvalidArgumentException('The client account has insufficient funds for this reversal.');
            }
            $rows[] = $this->row('Account '.$original->account->account_number, (float) $original->account->balance, $delta);
        }
        $reference = $original->originalTransaction ? 'RV-'.$original->originalTransaction->reference : $original->reference;
        $movements = DrawerMovement::with('session.drawer')->where('reference', $reference)->get();
        foreach ($movements->groupBy(fn ($movement) => $movement->session?->teller_drawer_id) as $group) {
            $drawer = $group->first()->session?->drawer;
            if (! $drawer || (float) $drawer->current_balance < (float) $group->where('type', 'cash_in')->sum('amount')) {
                throw new InvalidArgumentException('A linked drawer has insufficient cash or is missing.');
            }
            $rows[] = $this->row('Drawer '.$drawer->code, (float) $drawer->current_balance, (float) $group->sum(fn ($movement) => ($movement->type === 'cash_in' ? -1 : 1) * (float) $movement->amount));
        }
        $journalEffects = [];
        foreach (JournalEntry::with('lines')->where('transaction_id', $original->id)->get() as $journal) {
            if ($journal->lines->isNotEmpty()) {
                foreach ($journal->lines as $line) {
                    $journalEffects[$journal->branch_id ?: $original->branch_id][$line->chart_account_id] = ($journalEffects[$journal->branch_id ?: $original->branch_id][$line->chart_account_id] ?? 0) + (float) $line->credit - (float) $line->debit;
                }
            } elseif ($journal->chart_account_id) {
                $journalEffects[$journal->branch_id ?: $original->branch_id][$journal->chart_account_id] = ($journalEffects[$journal->branch_id ?: $original->branch_id][$journal->chart_account_id] ?? 0) + ($journal->entry_type === 'debit' ? -1 : 1) * (float) $journal->amount;
            }
        }
        foreach ($journalEffects as $branchId => $effects) {
            foreach ($effects as $ledgerId => $delta) {
                $ledger = ChartAccount::findOrFail($ledgerId);
                $rows[] = $this->row('Ledger '.$ledger->code.' · '.$ledger->name, $this->ledgers->currentBalance($ledger, (int) $branchId), $ledger->normal_balance === 'debit' ? $delta : -$delta);
            }
        }
        if ($movements->isEmpty()) {
            $notes[] = 'No linked drawer movements: drawer cash will remain unchanged.';
        }
        return $this->document($rows, $notes, (float) $original->amount, (float) $original->charges, $user);
    }
}
