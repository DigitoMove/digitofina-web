<?php

namespace App\Console\Commands;

use App\Services\InvestmentService;
use App\Services\OperationsMonitor;
use Illuminate\Console\Command;

class MarkMaturedInvestments extends Command
{
    protected $signature = 'investments:mark-matured';

    protected $description = 'Mark active investment accounts as matured when they reach maturity date';

    public function handle(InvestmentService $investments): int
    {
        $result = app(OperationsMonitor::class)->run('investment-maturities', fn (): array => ['matured_accounts' => $investments->markMatured()]);
        $count = $result['matured_accounts'];

        $this->info("Marked {$count} investment account(s) as matured.");

        return self::SUCCESS;
    }
}
