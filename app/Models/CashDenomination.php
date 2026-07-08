<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDenomination extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'denomination' => 'integer',
            'quantity' => 'integer',
            'total' => 'decimal:2',
        ];
    }

    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
    }
}
