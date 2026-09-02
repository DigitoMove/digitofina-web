<?php

use App\Models\MakerCheckerRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionReversalService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_REFERENCE = 'DF260831203402YTGSU';

    private const FIRST_REVERSAL_REFERENCE = 'RV260831204259UZRYZ';

    private const ERRONEOUS_REVERSAL_REFERENCE = 'RV260831225401OB4H7';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (
            ! Schema::hasTable('transactions')
            || ! Schema::hasTable('journal_entries')
            || ! Schema::hasTable('journal_lines')
            || ! Schema::hasTable('chart_accounts')
        ) {
            return;
        }

        $erroneousReversal = Transaction::query()
            ->withoutGlobalScope('tenant')
            ->where('reference', self::ERRONEOUS_REVERSAL_REFERENCE)
            ->first();

        if (! $erroneousReversal || $erroneousReversal->status === 'reversed' || $erroneousReversal->reversed_by !== null) {
            return;
        }

        $firstReversal = Transaction::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($erroneousReversal->reversal_of_id)
            ->first();
        $original = $firstReversal
            ? Transaction::query()->withoutGlobalScope('tenant')->whereKey($firstReversal->reversal_of_id)->first()
            : null;

        if (
            ! $firstReversal
            || ! $original
            || $firstReversal->reference !== self::FIRST_REVERSAL_REFERENCE
            || $original->reference !== self::ORIGINAL_REFERENCE
            || (float) $erroneousReversal->amount !== 315000.0
            || (float) $erroneousReversal->charges !== 105000.0
            || ! $this->affectsMerchantPaymentsAccount($erroneousReversal)
        ) {
            throw new RuntimeException('The Lukwanga merchant reversal chain does not match the reviewed production records. No correction was applied.');
        }

        $checker = User::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($erroneousReversal->user_id)
            ->first();

        if (! $checker) {
            throw new RuntimeException('The user who approved the erroneous merchant reversal no longer exists. No correction was applied.');
        }

        $correctionRequest = new MakerCheckerRequest([
            'subject_id' => $erroneousReversal->id,
            'reason' => 'Deployment correction: restore merchant payments ledger and remove the erroneous drawer adjustment.',
        ]);

        app(TransactionReversalService::class)->approve($correctionRequest, $checker, allowDrawerOverdraft: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The corrective financial transaction is intentionally not deleted or reversed.
    }

    private function affectsMerchantPaymentsAccount(Transaction $transaction): bool
    {
        return DB::table('journal_entries')
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_accounts', 'chart_accounts.id', '=', 'journal_lines.chart_account_id')
            ->where('journal_entries.transaction_id', $transaction->id)
            ->where(function ($query): void {
                $query->where('chart_accounts.name', 'Merchant Code Payments/Clearing')
                    ->orWhere('chart_accounts.name', 'like', '%Merchant%Payments%Clearing%');
            })
            ->exists();
    }
};
