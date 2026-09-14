<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanDisbursement extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'disbursed_amount' => 'decimal:2',
            'fees_deducted' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'disbursement_date' => 'date',
        ];
    }

    public function applicationFeeRefunds(): HasMany
    {
        return $this->hasMany(Transaction::class, 'application_fee_disbursement_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
