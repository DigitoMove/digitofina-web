<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanProductTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'code', 'description', 'min_amount', 'max_amount', 'min_term', 'max_term',
        'term_unit', 'annual_interest_rate', 'is_interest_free', 'interest_method', 'interest_frequency',
        'repayment_frequency', 'default_term_months', 'grace_period_days', 'penalty_type', 'penalty_rate',
        'processing_fee', 'insurance_fee', 'application_fee', 'required_savings_percentage',
        'require_guarantors', 'minimum_guarantors', 'require_collateral',
        'auto_deduct_repayment_from_savings', 'loan_account_number_prefix', 'is_active', 'status',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'annual_interest_rate' => 'decimal:4',
            'is_interest_free' => 'boolean',
            'penalty_rate' => 'decimal:4',
            'processing_fee' => 'decimal:2',
            'insurance_fee' => 'decimal:2',
            'application_fee' => 'decimal:2',
            'required_savings_percentage' => 'decimal:4',
            'require_guarantors' => 'boolean',
            'require_collateral' => 'boolean',
            'auto_deduct_repayment_from_savings' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
