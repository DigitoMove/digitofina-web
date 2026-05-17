<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;

class BranchGeneralLedgerService
{
    /**
     * @return array<int, array{suffix:string,name:string,type:string,normal_balance:string}>
     */
    public function defaults(): array
    {
        return [
            ['suffix' => '5200', 'name' => 'Welfare Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5210', 'name' => 'Transport Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5220', 'name' => 'Travel Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5230', 'name' => 'Office Supplies Expense', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '5290', 'name' => 'Other Expenses', 'type' => 'expense', 'normal_balance' => 'debit'],
            ['suffix' => '4300', 'name' => 'Other Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['suffix' => '4310', 'name' => 'Welfare Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['suffix' => '4320', 'name' => 'Other Business Income', 'type' => 'income', 'normal_balance' => 'credit'],
            ['suffix' => '4330', 'name' => 'Application Fees Income', 'type' => 'income', 'normal_balance' => 'credit'],
        ];
    }

    public function ensureDefaults(Branch $branch): void
    {
        foreach ($this->defaults() as $account) {
            ChartAccount::query()->updateOrCreate(
                ['code' => $branch->code.'-'.$account['suffix']],
                [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'normal_balance' => $account['normal_balance'],
                    'is_cash_account' => false,
                    'is_system' => false,
                    'is_active' => true,
                ],
            );
        }
    }
}
