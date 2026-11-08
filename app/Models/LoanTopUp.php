<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanTopUp extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'principal_added' => 'decimal:2',
            'interest_added' => 'decimal:2',
            'previous_maturity_date' => 'date',
            'new_maturity_date' => 'date',
            'schedule_before' => 'array',
            'schedule_after' => 'array',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(LoanDisbursement::class, 'loan_disbursement_id');
    }
}
