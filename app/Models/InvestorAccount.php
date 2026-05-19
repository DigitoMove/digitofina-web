<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestorAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_invested' => 'decimal:2',
            'total_returns_earned' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
            'current_investment_balance' => 'decimal:2',
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

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function investments(): HasMany
    {
        return $this->hasMany(InvestmentAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }
}
