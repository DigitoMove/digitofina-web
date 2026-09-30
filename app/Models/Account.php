<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'opened_at' => 'date',
            'is_organization_wide' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeOrganizationWideSavings(Builder $query): Builder
    {
        return $query->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', 'savings'))
            ->where(function (Builder $query): void {
                $query->where('is_organization_wide', true)
                    ->orWhereHas('client', fn (Builder $query): Builder => $query->withoutGlobalScope('tenant')->whereHas('user.role', fn (Builder $query): Builder => $query->where('slug', 'organization-admin')));
            });
    }

    public function isOrganizationWideSavings(): bool
    {
        return (bool) $this->is_organization_wide
            || ($this->accountType?->category === 'savings' && $this->client?->user?->hasRole('organization-admin'));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function loan(): HasOne
    {
        return $this->hasOne(Loan::class, 'loan_account_id');
    }
}
