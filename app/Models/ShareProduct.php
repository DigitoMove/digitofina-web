<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShareProduct extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'share_value' => 'decimal:2',
            'allows_transfer' => 'boolean',
            'dividend_eligible' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ShareAccount::class);
    }
}
