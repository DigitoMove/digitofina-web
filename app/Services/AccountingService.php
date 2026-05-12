<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\User;

class AccountingService
{
    public function __construct(private JournalEntryService $journals) {}

    /**
     * @param  array<int, array{account_code:string, debit?:float, credit?:float, narration?:string}>  $lines
     */
    public function postBalancedJournal(array $lines, string $description, ?User $user = null, ?int $branchId = null): JournalEntry
    {
        return $this->journals->post($lines, $description, $user, $branchId);
    }
}
