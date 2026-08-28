<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartAccountTemplate extends Model
{
    protected $fillable = [
        'key', 'scope', 'code', 'name', 'type', 'normal_balance', 'drawer_direction',
        'is_cash_account', 'is_system', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_cash_account' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
