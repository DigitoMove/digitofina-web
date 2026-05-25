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
            'target_duration_months' => 'integer',
            'expected_return_amount' => 'decimal:2',
            'actual_return_amount' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'locked_amount' => 'decimal:2',
            'return_rate' => 'decimal:4',
            'monthly_maintenance_fee' => 'decimal:2',
            'maintenance_fee_paid' => 'decimal:2',
            'client_investment_details' => 'array',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function progressPercentage(): int
    {
        if (! $this->start_date || ! $this->maturity_date) {
            return 0;
        }

        $totalDays = max(1, $this->start_date->diffInDays($this->maturity_date));
        $elapsedDays = max(0, min($totalDays, $this->start_date->diffInDays(today(), false)));

        return (int) min(100, round(($elapsedDays / $totalDays) * 100));
    }

    public function elapsedMonths(): int
    {
        if (! $this->start_date) {
            return 0;
        }

        return max(0, (int) $this->start_date->diffInMonths(today(), false));
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
