<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingsAccount extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'last_activity_at' => 'datetime',
            'dormant_at' => 'datetime',
            'current_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'locked_amount' => 'decimal:2',
            'is_organization_wide' => 'boolean',
        ];
    }

    /**
     * @param  Builder<SavingsAccount>  $query
     * @return Builder<SavingsAccount>
     */
    public function scopeOrganizationWide(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('is_organization_wide', true)
                ->orWhereHas('client', fn (Builder $query): Builder => $query->withoutGlobalScope('tenant')->whereHas('user.role', fn (Builder $query): Builder => $query->where('slug', 'organization-admin')));
        });
    }

    public function isOrganizationWide(): bool
    {
        return (bool) $this->is_organization_wide
            || $this->client?->user?->hasRole('organization-admin') === true;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'savings_product_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function fixedSavingsAccounts(): HasMany
    {
        return $this->hasMany(FixedSavingsAccount::class);
    }

    public function loanSecurities(): HasMany
    {
        return $this->hasMany(LoanSecurity::class);
    }
}
