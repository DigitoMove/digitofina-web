<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'penalty_paid' => 'decimal:2',
            'fees_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(LoanRepayment::class, 'loan_repayment_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LoanSchedule::class, 'loan_schedule_id');
    }
}
