<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MobileMoneyTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_response' => 'array',
            'reconciled_at' => 'datetime',
        ];
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }
}
