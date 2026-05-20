<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientGroup extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function chairperson(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'chairperson_client_id');
    }

    public function secretary(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'secretary_client_id');
    }

    public function treasurer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'treasurer_client_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_group_members')
            ->withPivot(['role', 'joined_at', 'left_at', 'status'])
            ->withTimestamps();
    }

    public function groupSavingsAccounts(): HasMany
    {
        return $this->hasMany(GroupSavingsAccount::class);
    }
}
