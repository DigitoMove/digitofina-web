<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charges' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class, 'transaction_type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
    }

    public function drawerMovements(): HasMany
    {
        return $this->hasMany(DrawerMovement::class, 'reference', 'reference');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversalRequests(): HasMany
    {
        return $this->hasMany(MakerCheckerRequest::class, 'subject_id')
            ->where('subject_type', $this->getMorphClass())
            ->where('action', 'transaction_reversal');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function loanRepayment(): HasOne
    {
        return $this->hasOne(LoanRepayment::class);
    }

    public function scopeNonCash(Builder $query): Builder
    {
        return $query
            ->whereNull('drawer_session_id')
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('journalEntries', fn (Builder $query): Builder => $query->where('journal_number', 'like', 'NC%'))
                    ->orWhereHas('loanRepayment', fn (Builder $query): Builder => $query->where('payment_method', 'non_cash'));
            });
    }
}
