<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvestmentAccount extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'unit_quantity' => 'integer',
            'unit_value' => 'decimal:2',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'expected_return_amount' => 'decimal:2',
            'actual_return_amount' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'locked_amount' => 'decimal:2',
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

    public function sponsorClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'sponsor_client_id');
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

    public function returns(): HasMany
    {
        return $this->hasMany(InvestmentReturn::class);
    }

    public function loanSecurities(): HasMany
    {
        return $this->hasMany(LoanSecurity::class);
    }
}
