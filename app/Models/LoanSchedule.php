<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal_due' => 'decimal:2',
            'interest_due' => 'decimal:2',
            'fees_due' => 'decimal:2',
            'penalty_due' => 'decimal:2',
            'total_due' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'fees_paid' => 'decimal:2',
            'penalty_paid' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'days_overdue' => 'integer',
            'last_reversal_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function scopeExcludingCancelledLoans(Builder $query): Builder
    {
        return $query->whereHas('loan', fn (Builder $query): Builder => $query->whereNotIn('status', [
            'draft',
            'rejected',
            'cancelled',
            'written_off',
        ]));
    }

    public function scopeForActiveArrearsLoans(Builder $query): Builder
    {
        return $query->whereHas('loan', fn (Builder $query): Builder => $query->forArrears());
    }

    public function scopeEligibleForArrears(Builder $query, CarbonInterface|string|null $asOf = null): Builder
    {
        $asOf = $asOf instanceof CarbonInterface
            ? $asOf
            : CarbonImmutable::parse($asOf ?: today()->toDateString());

        return $query
            ->forActiveArrearsLoans()
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<', $asOf->copy()->subDay()->toDateString());
    }
}
