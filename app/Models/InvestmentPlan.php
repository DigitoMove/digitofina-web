<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentPlan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'expected_return_rate' => 'decimal:4',
            'early_withdrawal_penalty' => 'decimal:4',
            'allow_top_ups' => 'boolean',
            'allow_early_withdrawal' => 'boolean',
            'auto_reinvest_returns' => 'boolean',
        ];
    }

    public function investmentAccounts(): HasMany
    {
        return $this->hasMany(InvestmentAccount::class);
    }
}
