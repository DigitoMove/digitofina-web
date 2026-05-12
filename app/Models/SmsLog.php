<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SmsProvider::class, 'sms_provider_id');
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
