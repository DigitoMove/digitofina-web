<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'principal_balance' => 'decimal:2',
            'interest_balance' => 'decimal:2',
            'fees_balance' => 'decimal:2',
            'penalty_balance' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'guarantors' => 'array',
            'documents' => 'array',
            'appraisal' => 'array',
            'submitted_at' => 'date',
            'approved_at' => 'date',
            'disbursed_at' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'savings_account_id');
    }

    public function loanAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loan_account_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LoanSchedule::class);
    }
}
