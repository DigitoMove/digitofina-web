<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProvider extends Model
{
    use BelongsToTenant;

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
