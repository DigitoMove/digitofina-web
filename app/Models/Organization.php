<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Organization extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = [
        'is_demo' => false,
    ];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'interest_settings' => 'array',
            'sms_settings' => 'array',
            'mobile_money_settings' => 'array',
            'social_links' => 'array',
            'is_demo' => 'boolean',
            'demo_started_at' => 'datetime',
            'demo_expires_at' => 'datetime',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function nylonPayCredential(): HasOne
    {
        return $this->hasOne(NylonPayCredential::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function loanProducts(): HasMany
    {
        return $this->hasMany(LoanProduct::class);
    }

    public function chartAccounts(): HasMany
    {
        return $this->hasMany(ChartAccount::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'organization_modules')
            ->withPivot(['is_enabled', 'settings', 'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at'])
            ->withTimestamps();
    }

    public function organizationModules(): HasMany
    {
        return $this->hasMany(OrganizationModule::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(OrganizationDomain::class);
    }

    public function commercialQuotes(): HasMany
    {
        return $this->hasMany(CommercialQuote::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationSubscription::class);
    }

    public function latestSubscription(): HasOne
    {
        return $this->hasOne(OrganizationSubscription::class)->latestOfMany('activated_at');
    }

    public function hostingFees(): HasMany
    {
        return $this->hasMany(OrganizationHostingFee::class);
    }

    public function latestHostingFee(): HasOne
    {
        return $this->hasOne(OrganizationHostingFee::class)->latestOfMany('period_starts_on');
    }

    public function hasExpiredSubscription(): bool
    {
        $subscription = $this->latestSubscription;

        return $subscription !== null
            && $subscription->billing_type === 'subscription'
            && $subscription->current_period_ends_at?->isPast()
            && $subscription->status === 'active';
    }

    public function isDemoExpired(?Carbon $at = null): bool
    {
        return $this->is_demo
            && $this->demo_expires_at !== null
            && $this->demo_expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function demoDaysRemaining(): int
    {
        if (! $this->is_demo || $this->demo_expires_at === null || $this->isDemoExpired()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($this->demo_expires_at, true) / 86400));
    }
}
