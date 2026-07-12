<?php

namespace App\Console\Commands;

use App\Services\LedgerReversalRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:repair-reversal-lines {--apply : Write missing reversal journals and lines} {--transaction= : Restrict repair to one original transaction id} {--limit= : Maximum original reversed transactions to scan}')]
#[Description('Find reversed transactions whose ledger journal lines were not reversed, and optionally repair them.')]
class RepairLedgerReversalLines extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LedgerReversalRepairService $repairs): int
    {
        $dryRun = ! (bool) $this->option('apply');
        $transactionId = $this->option('transaction') ? (int) $this->option('transaction') : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $summary = $repairs->repairMissingLines(
            dryRun: $dryRun,
            transactionId: $transactionId,
            limit: $limit,
            actor: null,
        );

        $this->components->info(($dryRun ? 'Dry run complete.' : 'Repair complete.').' Scanned '.$summary['scanned'].' reversed transaction(s).');
        $this->line('Affected transactions: '.$summary['affected_transactions']);
        $this->line('Reversal journals to create/created: '.$summary['journals_created']);
        $this->line('Existing reversal journals reused: '.$summary['journals_reused']);
        $this->line('Original journal statuses to restore/restored: '.$summary['journals_restored']);
        $this->line('Reversal journal lines to create/created: '.$summary['lines_created']);

        if ($summary['rows'] !== []) {
            $this->table(
                ['Original Tx', 'Original Ref', 'Reversal Tx', 'Reversal Ref', 'Journal', 'Lines', 'Create Journal', 'Restore Journal'],
                collect($summary['rows'])->map(fn (array $row): array => [
                    $row['transaction_id'],
                    $row['transaction_reference'],
                    $row['reversal_id'],
                    $row['reversal_reference'],
                    $row['journal_reference'],
                    $row['lines_created'],
                    $row['journal_created'] ? 'yes' : 'no',
                    $row['journal_restored'] ? 'yes' : 'no',
                ])->all(),
            );
        }

        if ($dryRun && ($summary['lines_created'] > 0 || $summary['journals_created'] > 0 || $summary['journals_restored'] > 0)) {
            $this->components->warn('Run again with --apply to write these repairs.');
        }

        return self::SUCCESS;
    }
}
