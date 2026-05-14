<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookProvider extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted:array',
            'requires_signature' => 'boolean',
            'signing_secret' => 'encrypted',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
