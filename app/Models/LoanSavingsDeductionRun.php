<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanSavingsDeductionRun extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LoanSavingsDeductionSchedule::class, 'schedule_id');
    }

    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class);
    }

    public function loanRepayment(): BelongsTo
    {
        return $this->belongsTo(LoanRepayment::class);
    }
}
