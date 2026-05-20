<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentReturn extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'return_rate' => 'decimal:4',
            'period_start' => 'date',
            'period_end' => 'date',
            'posting_date' => 'date',
        ];
    }

    public function investmentAccount(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
