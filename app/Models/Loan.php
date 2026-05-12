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
            'risk_score' => 'decimal:2',
            'credit_score' => 'decimal:2',
            'savings_balance_at_application' => 'decimal:2',
            'required_savings_amount' => 'decimal:2',
            'guarantors' => 'array',
            'documents' => 'array',
            'appraisal' => 'array',
            'application_date' => 'date',
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

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LoanSchedule::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LoanApproval::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(LoanDisbursement::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }
}
