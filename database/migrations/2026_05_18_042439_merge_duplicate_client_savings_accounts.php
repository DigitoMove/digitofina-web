<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->mergeSavingsAccountsTable();
            $this->mergeLegacySavingsAccounts();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }

    private function mergeSavingsAccountsTable(): void
    {
        if (! Schema::hasTable('savings_accounts')) {
            return;
        }

        $duplicateClientIds = DB::table('savings_accounts')
            ->select('client_id')
            ->whereNull('deleted_at')
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('client_id');

        foreach ($duplicateClientIds as $clientId) {
            $accounts = DB::table('savings_accounts')
                ->where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->orderBy('opening_date')
                ->orderBy('id')
                ->get();

            $keeper = $accounts->first();
            $extras = $accounts->skip(1);

            if (! $keeper || $extras->isEmpty()) {
                continue;
            }

            $extraIds = $extras->pluck('id')->all();
            $currentBalance = (float) $keeper->current_balance + (float) $extras->sum('current_balance');
            $availableBalance = (float) $keeper->available_balance + (float) $extras->sum('available_balance');

            if (Schema::hasTable('savings_transactions')) {
                DB::table('savings_transactions')
                    ->whereIn('savings_account_id', $extraIds)
                    ->update(['savings_account_id' => $keeper->id]);
            }

            DB::table('savings_accounts')
                ->where('id', $keeper->id)
                ->update([
                    'current_balance' => $currentBalance,
                    'available_balance' => $availableBalance,
                    'updated_at' => now(),
                ]);

            DB::table('savings_accounts')
                ->whereIn('id', $extraIds)
                ->delete();
        }
    }

    private function mergeLegacySavingsAccounts(): void
    {
        if (! Schema::hasTable('accounts') || ! Schema::hasTable('account_types')) {
            return;
        }

        $savingsTypeIds = DB::table('account_types')
            ->where('category', 'savings')
            ->pluck('id');

        if ($savingsTypeIds->isEmpty()) {
            return;
        }

        $duplicateClientIds = DB::table('accounts')
            ->select('client_id')
            ->whereIn('account_type_id', $savingsTypeIds)
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('client_id');

        foreach ($duplicateClientIds as $clientId) {
            $accounts = DB::table('accounts')
                ->where('client_id', $clientId)
                ->whereIn('account_type_id', $savingsTypeIds)
                ->orderBy('opened_at')
                ->orderBy('id')
                ->get();

            $keeper = $accounts->first();
            $extras = $accounts->skip(1);

            if (! $keeper || $extras->isEmpty()) {
                continue;
            }

            $extraIds = $extras->pluck('id')->all();
            $balance = (float) $keeper->balance + (float) $extras->sum('balance');

            if (Schema::hasTable('loans')) {
                DB::table('loans')
                    ->whereIn('savings_account_id', $extraIds)
                    ->update(['savings_account_id' => $keeper->id]);
            }

            if (Schema::hasTable('transactions')) {
                DB::table('transactions')
                    ->whereIn('account_id', $extraIds)
                    ->update(['account_id' => $keeper->id]);
            }

            DB::table('accounts')
                ->where('id', $keeper->id)
                ->update([
                    'balance' => $balance,
                    'updated_at' => now(),
                ]);

            DB::table('accounts')
                ->whereIn('id', $extraIds)
                ->delete();
        }
    }
};
