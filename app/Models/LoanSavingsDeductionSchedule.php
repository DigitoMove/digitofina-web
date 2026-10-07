<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanSavingsDeductionSchedule extends Model
{
    use BelongsToTenant;

    public const FREQUENCIES = ['daily', 'weekly', 'biweekly', 'monthly'];

    public const STATUSES = ['draft', 'active', 'disabled', 'cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'next_run_at' => 'datetime',
            'activated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function disabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(LoanSavingsDeductionRun::class, 'schedule_id');
    }
}
