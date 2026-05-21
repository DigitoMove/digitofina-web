<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvestmentPlan extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'expected_return_rate' => 'decimal:4',
            'early_withdrawal_penalty' => 'decimal:4',
            'early_withdrawal_penalty_value' => 'decimal:2',
            'allow_top_ups' => 'boolean',
            'allow_topups' => 'boolean',
            'allow_early_withdrawal' => 'boolean',
            'auto_reinvest_returns' => 'boolean',
            'auto_reinvest' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function investmentAccounts(): HasMany
    {
        return $this->hasMany(InvestmentAccount::class);
    }
}
