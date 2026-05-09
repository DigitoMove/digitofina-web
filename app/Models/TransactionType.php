<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'affects_cash_drawer' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
