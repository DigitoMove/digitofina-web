<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kyc_documents' => 'array',
            'registered_at' => 'date',
            'date_of_birth' => 'date',
            'closed_at' => 'datetime',
            'gps_latitude' => 'decimal:7',
            'gps_longitude' => 'decimal:7',
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

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ClientGroup::class, 'client_group_members')
            ->withPivot(['role', 'joined_at', 'left_at', 'status'])
            ->withTimestamps();
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function shareAccounts(): HasMany
    {
        return $this->hasMany(ShareAccount::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.($this->middle_name ? $this->middle_name.' ' : '').$this->last_name);
    }

    public function getKycCompletionPercentageAttribute(): int
    {
        $checks = $this->kyc_documents ?? [];

        if ($checks === []) {
            return 0;
        }

        $completed = collect($checks)->filter()->count();

        return (int) round(($completed / count($checks)) * 100);
    }
}
