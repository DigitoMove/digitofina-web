<?php

namespace App\Observers;

use App\Models\Branch;
use App\Services\FinancialDefaultsService;

class BranchObserver
{
    public function __construct(private FinancialDefaultsService $financialDefaults) {}

    public function created(Branch $branch): void
    {
        $this->financialDefaults->provisionBranch($branch);
    }
}
