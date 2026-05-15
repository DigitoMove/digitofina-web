<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSyncRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'client_updated_at' => 'datetime',
            'payload' => 'array',
            'validation_errors' => 'array',
            'conflict_data' => 'array',
            'response_data' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OfflineSyncBatch::class, 'offline_sync_batch_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
