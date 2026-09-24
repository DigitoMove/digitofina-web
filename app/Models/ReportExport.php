<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
