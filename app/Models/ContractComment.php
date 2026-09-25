<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractComment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OrganizationContract::class, 'organization_contract_id');
    }
}
