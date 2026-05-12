<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted:array',
            'is_default' => 'boolean',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }
}
