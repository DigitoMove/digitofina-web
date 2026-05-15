<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchDailySummary extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'savings_deposits' => 'decimal:2',
            'savings_withdrawals' => 'decimal:2',
            'loan_repayments' => 'decimal:2',
            'loan_disbursements' => 'decimal:2',
            'penalties_applied' => 'decimal:2',
            'journal_debits' => 'decimal:2',
            'journal_credits' => 'decimal:2',
            'metadata' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function financialDay(): BelongsTo
    {
        return $this->belongsTo(FinancialDay::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
