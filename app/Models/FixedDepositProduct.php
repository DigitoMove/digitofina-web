<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedDepositProduct extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'early_withdrawal_penalty_rate' => 'decimal:4',
            'allows_rollover' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(FixedDepositAccount::class);
    }
}
