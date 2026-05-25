<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanProduct extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'min_term' => 'integer',
            'max_term' => 'integer',
            'annual_interest_rate' => 'decimal:4',
            'is_interest_free' => 'boolean',
            'penalty_rate' => 'decimal:4',
            'processing_fee' => 'decimal:2',
            'insurance_fee' => 'decimal:2',
            'application_fee' => 'decimal:2',
            'required_savings_percentage' => 'decimal:4',
            'require_guarantors' => 'boolean',
            'minimum_guarantors' => 'integer',
            'require_collateral' => 'boolean',
            'auto_deduct_repayment_from_savings' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
