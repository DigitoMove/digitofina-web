<?php

namespace App\Console\Commands;

use App\Services\InvestmentService;
use Illuminate\Console\Command;

class MarkMaturedInvestments extends Command
{
    protected $signature = 'investments:mark-matured';

    protected $description = 'Mark active investment accounts as matured when they reach maturity date';

    public function handle(InvestmentService $investments): int
    {
        $count = $investments->markMatured();

        $this->info("Marked {$count} investment account(s) as matured.");

        return self::SUCCESS;
    }
}
