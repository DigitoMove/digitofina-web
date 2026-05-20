<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupSavingsAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'contribution_amount' => 'decimal:2',
            'group_savings_balance' => 'decimal:2',
        ];
    }

    public function clientGroup(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GroupSavingsContribution::class);
    }
}
