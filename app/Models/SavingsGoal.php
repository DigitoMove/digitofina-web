<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingsGoal extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'current_saved_amount' => 'decimal:2',
            'expected_contribution_amount' => 'decimal:2',
            'start_date' => 'date',
            'target_date' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function linkedSavingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'savings_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsGoalTransaction::class);
    }

    public function getPercentageAchievedAttribute(): float
    {
        return (float) $this->target_amount > 0
            ? round(min(100, ((float) $this->current_saved_amount / (float) $this->target_amount) * 100), 2)
            : 0.0;
    }

    public function getRemainingAmountAttribute(): float
    {
        return round(max(0, (float) $this->target_amount - (float) $this->current_saved_amount), 2);
    }

    public function getDaysRemainingAttribute(): int
    {
        return max(0, today()->diffInDays($this->target_date, false));
    }

    public function getMonthlyRequiredContributionAttribute(): float
    {
        $months = max(1, today()->diffInMonths($this->target_date, false));

        return round($this->remaining_amount / $months, 2);
    }
}
