<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'expected_return_amount' => 'decimal:2',
            'actual_return_amount' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'return_rate' => 'decimal:4',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function investorAccount(): BelongsTo
    {
        return $this->belongsTo(InvestorAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InvestmentPlan::class, 'investment_plan_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }
}
