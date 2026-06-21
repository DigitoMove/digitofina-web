<?php

namespace App\Services;

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

class DrawerTransferService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private BranchGeneralLedgerService $generalLedger,
        private FinancialControlService $financialControls,
    ) {}

    public function transfer(DrawerSession $sourceSession, DrawerSession $destinationSession, float $amount, float $charges, User $user, ?string $description = null, ?CarbonInterface $postedAt = null): Transaction
    {
        $postedAt = TransactionTimestamp::forBusinessDate($postedAt);
        CurrencyDenomination::assertValid($amount, 'Transfer amount');

        if ($charges < 0) {
            throw new InvalidArgumentException('Charges cannot be negative.');
        }

        if ($charges > 0) {
            CurrencyDenomination::assertValid($charges, 'Charges');
        }

        return DB::transaction(function () use ($sourceSession, $destinationSession, $amount, $charges, $user, $description, $postedAt): Transaction {
            $sessions = DrawerSession::query()
                ->whereIn('id', [$sourceSession->id, $destinationSession->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sourceSession = $sessions->get($sourceSession->id);
            $destinationSession = $sessions->get($destinationSession->id);

            if (! $sourceSession || ! $destinationSession || $sourceSession->id === $destinationSession->id) {
                throw new InvalidArgumentException('Select two different open drawer sessions.');
            }

            $drawers = TellerDrawer::query()
                ->withoutGlobalScope('tenant')
                ->with('branch')
                ->whereIn('id', [$sourceSession->teller_drawer_id, $destinationSession->teller_drawer_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sourceDrawer = $drawers->get($sourceSession->teller_drawer_id);
            $destinationDrawer = $drawers->get($destinationSession->teller_drawer_id);

            if (! $sourceDrawer || ! $destinationDrawer || $sourceDrawer->id === $destinationDrawer->id) {
                throw new InvalidArgumentException('Select two different drawers.');
            }

            if ($sourceSession->status !== 'open' || $destinationSession->status !== 'open' || $sourceDrawer->status !== 'open' || $destinationDrawer->status !== 'open') {
                throw new InvalidArgumentException('Both drawers must have open sessions before cash can be transferred.');
            }

            if ((int) $sourceDrawer->branch?->organization_id !== (int) $destinationDrawer->branch?->organization_id) {
                throw new InvalidArgumentException('Cash cannot be transferred between different organizations.');
            }

            if ($sourceDrawer->currency !== $destinationDrawer->currency) {
                throw new InvalidArgumentException('Both drawers must use the same currency.');
            }

            $totalCashOut = $amount + $charges;

            if ((float) $sourceDrawer->current_balance < $totalCashOut) {
                throw new InvalidArgumentException('The source drawer does not have enough cash for the transfer and charges.');
            }

            $this->financialControls->ensureFinancialDayOpen($user, (int) $sourceDrawer->branch_id, $postedAt);

            if ((int) $destinationDrawer->branch_id !== (int) $sourceDrawer->branch_id) {
                $this->financialControls->ensureFinancialDayOpen($user, (int) $destinationDrawer->branch_id, $postedAt);
            }

            $this->financialControls->enforceTransactionLimit($user, $totalCashOut, $postedAt);

            $sourceDrawer->decrement('current_balance', $totalCashOut);
            $destinationDrawer->increment('current_balance', $amount);
            $sourceDrawer->refresh();
            $destinationDrawer->refresh();

            $type = TransactionType::query()->firstOrCreate(
                ['code' => 'drawer_transfer'],
                [
                    'name' => 'Drawer Transfer',
                    'direction' => 'debit',
                    'affects_cash_drawer' => true,
                    'is_active' => true,
                ],
            );
            $reference = 'DT'.now()->format('ymdHis').Str::upper(Str::random(5));
            $narration = $description ?: 'Cash transfer from '.$sourceDrawer->code.' to '.$destinationDrawer->code;
            $transaction = Transaction::create([
                'transaction_type_id' => $type->id,
                'drawer_session_id' => $sourceSession->id,
                'branch_id' => $sourceDrawer->branch_id,
                'user_id' => $user->id,
                'reference' => $reference,
                'amount' => $amount,
                'charges' => $charges,
                'balance_after' => $sourceDrawer->current_balance,
                'currency' => $sourceDrawer->currency,
                'description' => $narration,
                'status' => 'posted',
                'posted_at' => $postedAt,
            ]);

            DrawerMovement::create([
                'drawer_session_id' => $sourceSession->id,
                'user_id' => $user->id,
                'type' => 'cash_out',
                'amount' => $totalCashOut,
                'balance_after' => $sourceDrawer->current_balance,
                'reference' => $reference,
                'description' => $narration.($charges > 0 ? ' (includes charges of '.number_format($charges, 2).')' : ''),
            ]);
            DrawerMovement::create([
                'drawer_session_id' => $destinationSession->id,
                'user_id' => $user->id,
                'type' => 'cash_in',
                'amount' => $amount,
                'balance_after' => $destinationDrawer->current_balance,
                'reference' => $reference,
                'description' => $narration,
            ]);

            if ($charges > 0) {
                $this->postChargeJournal($transaction, $sourceDrawer, $charges, $user, $postedAt, $narration);
            }

            $this->auditLogger->record('drawer.cash_transferred', $transaction, [
                'source_drawer_id' => $sourceDrawer->id,
                'destination_drawer_id' => $destinationDrawer->id,
                'amount' => $amount,
                'charges' => $charges,
                'reference' => $reference,
            ]);

            return $transaction->refresh();
        }, attempts: 3);
    }

    private function postChargeJournal(Transaction $transaction, TellerDrawer $drawer, float $charges, User $user, CarbonInterface $postedAt, string $narration): void
    {
        $chargeAccount = $this->generalLedger->transactionChargesExpense($drawer->branch);
        $cashAccount = $this->cashAccount($drawer);
        $journal = JournalEntry::create([
            'organization_id' => $drawer->branch->organization_id,
            'branch_id' => $drawer->branch_id,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'journal_number' => 'DC'.now()->format('ymdHis').Str::upper(Str::random(4)),
            'journal_date' => $postedAt->toDateString(),
            'description' => 'Drawer transfer charges',
            'status' => 'posted',
            'posted_at' => $postedAt,
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'account_name' => $chargeAccount->name,
            'entry_type' => 'debit',
            'amount' => $charges,
            'memo' => $narration,
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
                'narration' => 'Drawer transfer charges',
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
            throw new InvalidArgumentException('Configure an active cash ledger before posting drawer charges.');
        }

        return $cashAccount;
    }
}
