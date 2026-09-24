<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedSavingsAccount extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'interest_rate' => 'decimal:4',
            'expected_interest' => 'decimal:2',
            'actual_interest' => 'decimal:2',
            'early_withdrawal_allowed' => 'boolean',
            'early_withdrawal_penalty' => 'decimal:4',
            'closed_at' => 'datetime',
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

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }
}
