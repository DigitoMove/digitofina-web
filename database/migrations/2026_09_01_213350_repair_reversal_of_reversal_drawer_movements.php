<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (
            ! Schema::hasTable('transactions')
            || ! Schema::hasTable('drawer_movements')
            || ! Schema::hasTable('drawer_sessions')
            || ! Schema::hasTable('teller_drawers')
        ) {
            return;
        }

        DB::table('transactions as restoring_reversals')
            ->join('transactions as reversed_reversals', 'reversed_reversals.id', '=', 'restoring_reversals.reversal_of_id')
            ->join('transactions as original_transactions', 'original_transactions.id', '=', 'reversed_reversals.reversal_of_id')
            ->whereNotNull('reversed_reversals.reversal_of_id')
            ->select([
                'restoring_reversals.id',
                'restoring_reversals.organization_id',
                'restoring_reversals.user_id',
                'restoring_reversals.posted_at',
                'restoring_reversals.created_at',
                'reversed_reversals.reference as reversed_reversal_reference',
                'original_transactions.reference as original_reference',
            ])
            ->orderBy('restoring_reversals.id')
            ->get()
            ->each(fn (object $restoringReversal) => $this->repairMissingDrawerMovements($restoringReversal));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Historical cash-balance repairs are intentionally not reversed.
    }

    private function repairMissingDrawerMovements(object $restoringReversal): void
    {
        $sourceReference = 'RV-'.$restoringReversal->original_reference;
        $restoredReference = 'RV-'.$restoringReversal->reversed_reversal_reference;
        $sourceMovements = DB::table('drawer_movements')
            ->join('drawer_sessions', 'drawer_sessions.id', '=', 'drawer_movements.drawer_session_id')
            ->where('drawer_movements.reference', $sourceReference)
            ->select([
                'drawer_movements.id',
                'drawer_movements.drawer_session_id',
                'drawer_movements.type',
                'drawer_movements.amount',
                'drawer_sessions.teller_drawer_id',
            ])
            ->orderBy('drawer_movements.id')
            ->get();

        if ($sourceMovements->isEmpty()) {
            return;
        }

        $sourceMovements
            ->groupBy('teller_drawer_id')
            ->each(function (Collection $drawerMovements, int|string $drawerId) use ($restoredReference, $restoringReversal): void {
                DB::transaction(function () use ($drawerMovements, $drawerId, $restoredReference, $restoringReversal): void {
                    $drawer = DB::table('teller_drawers')
                        ->where('id', $drawerId)
                        ->lockForUpdate()
                        ->first();

                    if (! $drawer) {
                        return;
                    }

                    $sessionIds = DB::table('drawer_sessions')
                        ->where('teller_drawer_id', $drawerId)
                        ->pluck('id');

                    if (DB::table('drawer_movements')->whereIn('drawer_session_id', $sessionIds)->where('reference', $restoredReference)->exists()) {
                        return;
                    }

                    $restoredAt = $restoringReversal->posted_at ?: $restoringReversal->created_at;
                    $previousBalance = DB::table('drawer_movements')
                        ->whereIn('drawer_session_id', $sessionIds)
                        ->where('created_at', '<', $restoredAt)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('balance_after');

                    if ($previousBalance === null) {
                        $previousBalance = DB::table('drawer_sessions')
                            ->where('id', $drawerMovements->first()->drawer_session_id)
                            ->value('opening_balance') ?? 0;
                    }

                    $runningBalance = (float) $previousBalance;
                    $restoredRows = $drawerMovements->map(function (object $movement) use (&$runningBalance, $restoredReference, $restoringReversal): array {
                        $restoredType = $movement->type === 'cash_in' ? 'cash_out' : 'cash_in';
                        $amount = (float) $movement->amount;
                        $runningBalance += $restoredType === 'cash_in' ? $amount : -$amount;

                        return [
                            'organization_id' => $restoringReversal->organization_id,
                            'drawer_session_id' => $movement->drawer_session_id,
                            'user_id' => $restoringReversal->user_id,
                            'type' => $restoredType,
                            'amount' => $amount,
                            'balance_after' => $runningBalance,
                            'reference' => $restoredReference,
                            'description' => 'Reversal of '.$restoringReversal->reversed_reversal_reference,
                            'created_at' => $restoringReversal->created_at ?: $restoredAt,
                            'updated_at' => $restoringReversal->created_at ?: $restoredAt,
                        ];
                    });
                    $netChange = $restoredRows->sum(fn (array $row): float => $row['type'] === 'cash_in' ? $row['amount'] : -$row['amount']);
                    $laterMovements = DB::table('drawer_movements')
                        ->whereIn('drawer_session_id', $sessionIds)
                        ->where('created_at', '>=', $restoredAt);

                    if ($netChange >= 0) {
                        $laterMovements->increment('balance_after', $netChange);
                        DB::table('teller_drawers')->where('id', $drawerId)->increment('current_balance', $netChange);
                    } else {
                        $laterMovements->decrement('balance_after', abs($netChange));
                        DB::table('teller_drawers')->where('id', $drawerId)->decrement('current_balance', abs($netChange));
                    }

                    DB::table('drawer_movements')->insert($restoredRows->all());
                });
            });
    }
};
