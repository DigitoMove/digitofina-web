<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleFinancialLimit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'single_transaction_limit' => 'decimal:2',
            'daily_transaction_limit' => 'decimal:2',
            'maker_checker_threshold' => 'decimal:2',
            'loan_approval_limit' => 'decimal:2',
            'backdated_transaction_limit' => 'decimal:2',
            'suspicious_transaction_threshold' => 'decimal:2',
            'max_backdate_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
