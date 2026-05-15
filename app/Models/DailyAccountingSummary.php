<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAccountingSummary extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'debit_total' => 'decimal:2',
            'credit_total' => 'decimal:2',
            'net_movement' => 'decimal:2',
            'generated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function financialDay(): BelongsTo
    {
        return $this->belongsTo(FinancialDay::class);
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
