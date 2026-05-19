<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\FixedDepositAccount;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShareAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClientDeletionService
{
    /**
     * @return array<string, int>
     */
    public function delete(Client $client): array
    {
        return DB::transaction(function () use ($client): array {
            $client = Client::withTrashed()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            $ids = $this->relatedIds($client);
            $counts = [];

            $this->deleteDocumentFiles($ids['client_document_ids']);
            $this->deleteMorphRows($ids, $counts);
            $this->deletePaymentRows($ids, $counts);
            $this->deleteTransactions($ids, $counts);
            $this->deleteLoanRows($ids, $counts);
            $this->deleteSavingsRows($ids, $counts);
            $this->deleteShareRows($ids, $counts);
            $this->deleteFixedDepositRows($ids, $counts);
            $this->deleteClientProfileRows($ids, $counts);
            $this->deleteAccounts($ids, $counts);

            $counts['clients'] = $this->deleteByIds('clients', collect([$client->id]));

            return $counts;
        });
    }

    /**
     * @return array<string, Collection<int, int|string>>
     */
    private function relatedIds(Client $client): array
    {
        $accountIds = $this->ids('accounts', 'client_id', $client->id);
        $transactionIds = $this->idsIn('transactions', 'account_id', $accountIds);
        $transactionRefs = $this->valuesIn('transactions', 'reference', 'id', $transactionIds);

        $loanIds = $this->ids('loans', 'client_id', $client->id);
        $loanScheduleIds = $this->idsIn('loan_schedules', 'loan_id', $loanIds);
        $loanRepaymentIds = $this->idsIn('loan_repayments', 'loan_id', $loanIds)
            ->merge($this->ids('loan_repayments', 'client_id', $client->id))
            ->unique()
            ->values();
        $loanTransactionIds = $this->valuesIn('loan_repayments', 'transaction_id', 'id', $loanRepaymentIds);

        $savingsAccountIds = $this->ids('savings_accounts', 'client_id', $client->id);
        $savingsTransactionIds = $this->ids('savings_transactions', 'client_id', $client->id)
            ->merge($this->idsIn('savings_transactions', 'savings_account_id', $savingsAccountIds))
            ->unique()
            ->values();

        $shareAccountIds = $this->ids('share_accounts', 'client_id', $client->id);
        $fixedDepositAccountIds = $this->ids('fixed_deposit_accounts', 'client_id', $client->id);

        return [
            'client_ids' => collect([$client->id]),
            'account_ids' => $accountIds,
            'transaction_ids' => $transactionIds->merge($loanTransactionIds)->unique()->filter()->values(),
            'transaction_refs' => $transactionRefs,
            'loan_ids' => $loanIds,
            'loan_schedule_ids' => $loanScheduleIds,
            'loan_repayment_ids' => $loanRepaymentIds,
            'savings_account_ids' => $savingsAccountIds,
            'savings_transaction_ids' => $savingsTransactionIds,
            'share_account_ids' => $shareAccountIds,
            'share_transaction_ids' => $this->idsIn('share_transactions', 'share_account_id', $shareAccountIds),
            'fixed_deposit_account_ids' => $fixedDepositAccountIds,
            'fixed_deposit_transaction_ids' => $this->idsIn('fixed_deposit_transactions', 'fixed_deposit_account_id', $fixedDepositAccountIds),
            'client_document_ids' => $this->ids('client_documents', 'client_id', $client->id),
            'client_note_ids' => $this->ids('client_notes', 'client_id', $client->id),
            'collateral_ids' => $this->ids('collaterals', 'client_id', $client->id)
                ->merge($this->idsIn('collaterals', 'loan_id', $loanIds))
                ->unique()
                ->values(),
            'guarantor_ids' => $this->idsIn('guarantors', 'loan_id', $loanIds)
                ->merge($this->ids('guarantors', 'guarantor_client_id', $client->id))
                ->unique()
                ->values(),
        ];
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteMorphRows(array $ids, array &$counts): void
    {
        $morphs = [
            Client::class => $ids['client_ids'],
            Account::class => $ids['account_ids'],
            Transaction::class => $ids['transaction_ids'],
            Loan::class => $ids['loan_ids'],
            LoanSchedule::class => $ids['loan_schedule_ids'],
            LoanRepayment::class => $ids['loan_repayment_ids'],
            SavingsAccount::class => $ids['savings_account_ids'],
            SavingsTransaction::class => $ids['savings_transaction_ids'],
            ShareAccount::class => $ids['share_account_ids'],
            FixedDepositAccount::class => $ids['fixed_deposit_account_ids'],
            ClientDocument::class => $ids['client_document_ids'],
        ];

        foreach ($morphs as $class => $modelIds) {
            if ($modelIds->isEmpty()) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;
            $morphClass = $model->getMorphClass();

            $counts['maker_checker_requests'] = ($counts['maker_checker_requests'] ?? 0)
                + $this->deleteMorph('maker_checker_requests', 'subject', $morphClass, $modelIds);
            $counts['approvals'] = ($counts['approvals'] ?? 0)
                + $this->deleteMorph('approvals', 'approvable', $morphClass, $modelIds);
            $counts['suspicious_transaction_alerts'] = ($counts['suspicious_transaction_alerts'] ?? 0)
                + $this->deleteMorph('suspicious_transaction_alerts', 'alertable', $morphClass, $modelIds);
            $counts['audit_logs'] = ($counts['audit_logs'] ?? 0)
                + $this->deleteAuditRows($morphClass, $modelIds);
        }
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deletePaymentRows(array $ids, array &$counts): void
    {
        $savingsTransactionMorph = (new SavingsTransaction)->getMorphClass();

        $counts['mobile_money_transactions'] = $this->deleteMorph('mobile_money_transactions', 'allocatable', (new Transaction)->getMorphClass(), $ids['transaction_ids'])
            + $this->deleteMorph('mobile_money_transactions', 'allocatable', (new SavingsAccount)->getMorphClass(), $ids['savings_account_ids'])
            + $this->deleteMorph('mobile_money_transactions', 'allocatable', $savingsTransactionMorph, $ids['savings_transaction_ids']);

        $paymentRequestIds = $this->idsMorph('payment_requests', 'payable', (new Transaction)->getMorphClass(), $ids['transaction_ids'])
            ->merge($this->idsMorph('payment_requests', 'payable', (new SavingsAccount)->getMorphClass(), $ids['savings_account_ids']))
            ->merge($this->idsMorph('payment_requests', 'payable', $savingsTransactionMorph, $ids['savings_transaction_ids']))
            ->unique()
            ->values();

        $counts['payment_callbacks'] = $this->deleteByIds('payment_callbacks', $this->idsIn('payment_callbacks', 'payment_request_id', $paymentRequestIds));
        $counts['payment_requests'] = $this->deleteByIds('payment_requests', $paymentRequestIds);
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteTransactions(array $ids, array &$counts): void
    {
        $journalIds = $this->idsIn('journal_entries', 'transaction_id', $ids['transaction_ids']);
        $counts['journal_lines'] = $this->deleteByIds('journal_lines', $this->idsIn('journal_lines', 'journal_entry_id', $journalIds));
        $counts['journal_entries'] = $this->deleteByIds('journal_entries', $journalIds);

        if (Schema::hasTable('drawer_movements') && $ids['transaction_refs']->isNotEmpty()) {
            $counts['drawer_movements'] = DB::table('drawer_movements')->whereIn('reference', $ids['transaction_refs'])->delete();
        }

        $counts['transactions'] = $this->deleteByIds('transactions', $ids['transaction_ids']);
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteLoanRows(array $ids, array &$counts): void
    {
        $counts['loan_repayment_allocations'] = $this->deleteByIds('loan_repayment_allocations', $this->idsIn('loan_repayment_allocations', 'loan_repayment_id', $ids['loan_repayment_ids']))
            + $this->deleteByIds('loan_repayment_allocations', $this->idsIn('loan_repayment_allocations', 'loan_schedule_id', $ids['loan_schedule_ids']));
        $counts['loan_penalties'] = $this->deleteByIds('loan_penalties', $this->idsIn('loan_penalties', 'loan_id', $ids['loan_ids']))
            + $this->deleteByIds('loan_penalties', $this->idsIn('loan_penalties', 'loan_schedule_id', $ids['loan_schedule_ids']));
        $counts['loan_write_offs'] = $this->deleteByIds('loan_write_offs', $this->idsIn('loan_write_offs', 'loan_id', $ids['loan_ids']));
        $counts['loan_restructures'] = $this->deleteByIds('loan_restructures', $this->idsIn('loan_restructures', 'loan_id', $ids['loan_ids']));
        $counts['loan_approvals'] = $this->deleteByIds('loan_approvals', $this->idsIn('loan_approvals', 'loan_id', $ids['loan_ids']));
        $counts['loan_disbursements'] = $this->deleteByIds('loan_disbursements', $this->idsIn('loan_disbursements', 'loan_id', $ids['loan_ids']));
        $counts['guarantors'] = $this->deleteByIds('guarantors', $ids['guarantor_ids']);
        $counts['collaterals'] = $this->deleteByIds('collaterals', $ids['collateral_ids']);
        $counts['loan_repayments'] = $this->deleteByIds('loan_repayments', $ids['loan_repayment_ids']);
        $counts['loan_schedules'] = $this->deleteByIds('loan_schedules', $ids['loan_schedule_ids']);
        $counts['loans'] = $this->deleteByIds('loans', $ids['loan_ids']);
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteSavingsRows(array $ids, array &$counts): void
    {
        $counts['savings_transactions'] = $this->deleteByIds('savings_transactions', $ids['savings_transaction_ids']);
        $counts['savings_accounts'] = $this->deleteByIds('savings_accounts', $ids['savings_account_ids']);
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteShareRows(array $ids, array &$counts): void
    {
        $counts['share_transactions'] = $this->deleteByIds('share_transactions', $ids['share_transaction_ids']);
        $counts['share_accounts'] = $this->deleteByIds('share_accounts', $ids['share_account_ids']);
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteFixedDepositRows(array $ids, array &$counts): void
    {
        $counts['fixed_deposit_transactions'] = $this->deleteByIds('fixed_deposit_transactions', $ids['fixed_deposit_transaction_ids']);
        $counts['fixed_deposit_accounts'] = $this->deleteByIds('fixed_deposit_accounts', $ids['fixed_deposit_account_ids']);
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteClientProfileRows(array $ids, array &$counts): void
    {
        $counts['client_group_members'] = Schema::hasTable('client_group_members')
            ? $this->deleteByIds('client_group_members', DB::table('client_group_members')->whereIn('client_id', $ids['client_ids'])->pluck('id'))
            : 0;
        $counts['client_documents'] = $this->deleteByIds('client_documents', $ids['client_document_ids']);
        $counts['client_notes'] = $this->deleteByIds('client_notes', $ids['client_note_ids']);

        if (Schema::hasTable('client_groups')) {
            DB::table('client_groups')->whereIn('chairperson_client_id', $ids['client_ids'])->update(['chairperson_client_id' => null]);
            DB::table('client_groups')->whereIn('secretary_client_id', $ids['client_ids'])->update(['secretary_client_id' => null]);
            DB::table('client_groups')->whereIn('treasurer_client_id', $ids['client_ids'])->update(['treasurer_client_id' => null]);
        }
    }

    /**
     * @param  array<string, Collection<int, int|string>>  $ids
     * @param  array<string, int>  $counts
     */
    private function deleteAccounts(array $ids, array &$counts): void
    {
        $counts['accounts'] = $this->deleteByIds('accounts', $ids['account_ids']);
    }

    /**
     * @param  Collection<int, int|string>  $documentIds
     */
    private function deleteDocumentFiles(Collection $documentIds): void
    {
        if (! Schema::hasTable('client_documents') || $documentIds->isEmpty()) {
            return;
        }

        ClientDocument::withTrashed()
            ->whereIn('id', $documentIds)
            ->get(['disk', 'path'])
            ->each(function (ClientDocument $document): void {
                if ($document->path) {
                    Storage::disk($document->disk ?: 'local')->delete($document->path);
                }
            });
    }

    /**
     * @param  Collection<int, int|string>  $ids
     */
    private function deleteByIds(string $table, Collection $ids): int
    {
        if (! Schema::hasTable($table) || $ids->isEmpty()) {
            return 0;
        }

        return DB::table($table)->whereIn('id', $ids->filter()->unique()->values())->delete();
    }

    private function ids(string $table, string $column, int $value): Collection
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return collect();
        }

        return DB::table($table)->where($column, $value)->pluck('id');
    }

    /**
     * @param  Collection<int, int|string>  $values
     */
    private function idsIn(string $table, string $column, Collection $values): Collection
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $values->filter()->isEmpty()) {
            return collect();
        }

        return DB::table($table)->whereIn($column, $values->filter()->unique()->values())->pluck('id');
    }

    /**
     * @param  Collection<int, int|string>  $values
     */
    private function valuesIn(string $table, string $select, string $column, Collection $values): Collection
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, $select) || $values->filter()->isEmpty()) {
            return collect();
        }

        return DB::table($table)->whereIn($column, $values->filter()->unique()->values())->pluck($select);
    }

    /**
     * @param  Collection<int, int|string>  $ids
     */
    private function idsMorph(string $table, string $morph, string $type, Collection $ids): Collection
    {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $morph.'_type')
            || ! Schema::hasColumn($table, $morph.'_id')
            || $ids->isEmpty()
        ) {
            return collect();
        }

        return DB::table($table)
            ->where($morph.'_type', $type)
            ->whereIn($morph.'_id', $ids->filter()->unique()->values())
            ->pluck('id');
    }

    /**
     * @param  Collection<int, int|string>  $ids
     */
    private function deleteMorph(string $table, string $morph, string $type, Collection $ids): int
    {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $morph.'_type')
            || ! Schema::hasColumn($table, $morph.'_id')
            || $ids->isEmpty()
        ) {
            return 0;
        }

        return DB::table($table)
            ->where($morph.'_type', $type)
            ->whereIn($morph.'_id', $ids->filter()->unique()->values())
            ->delete();
    }

    /**
     * @param  Collection<int, int|string>  $ids
     */
    private function deleteAuditRows(string $type, Collection $ids): int
    {
        if (! Schema::hasTable('audit_logs') || $ids->isEmpty()) {
            return 0;
        }

        $hasAuditableColumns = Schema::hasColumn('audit_logs', 'auditable_type') && Schema::hasColumn('audit_logs', 'auditable_id');
        $hasRecordColumns = Schema::hasColumn('audit_logs', 'record_type') && Schema::hasColumn('audit_logs', 'record_id');

        if (! $hasAuditableColumns && ! $hasRecordColumns) {
            return 0;
        }

        return DB::table('audit_logs')
            ->where(function ($query) use ($hasAuditableColumns, $hasRecordColumns, $type, $ids): void {
                if ($hasAuditableColumns) {
                    $query->where(function ($query) use ($type, $ids): void {
                        $query->where('auditable_type', $type)->whereIn('auditable_id', $ids);
                    });
                }

                if ($hasRecordColumns) {
                    $method = $hasAuditableColumns ? 'orWhere' : 'where';

                    $query->{$method}(function ($query) use ($type, $ids): void {
                        $query->where('record_type', $type)->whereIn('record_id', $ids);
                    });
                }
            })
            ->delete();
    }
}
