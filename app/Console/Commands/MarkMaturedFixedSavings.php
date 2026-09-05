<?php

namespace App\Console\Commands;

use App\Services\FixedSavingsService;
use App\Services\OperationsMonitor;
use Illuminate\Console\Command;

class MarkMaturedFixedSavings extends Command
{
    protected $signature = 'savings:mark-fixed-matured';

    protected $description = 'Mark fixed savings accounts as matured when they reach maturity date';

    public function handle(FixedSavingsService $fixedSavings): int
    {
        $result = app(OperationsMonitor::class)->run('fixed-savings-maturities', fn (): array => ['matured_accounts' => $fixedSavings->markMatured()]);
        $count = $result['matured_accounts'];

        $this->info("Marked {$count} fixed savings account(s) as matured.");

        return self::SUCCESS;
    }
}
