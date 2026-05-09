<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanRepayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'penalty_paid' => 'decimal:2',
            'fees_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }
}
