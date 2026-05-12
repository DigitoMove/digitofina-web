<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingsProduct extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'minimum_opening_balance' => 'decimal:2',
            'minimum_balance' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'withdrawal_charge' => 'decimal:2',
            'deposit_charge' => 'decimal:2',
            'monthly_maintenance_fee' => 'decimal:2',
            'allows_overdraft' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
