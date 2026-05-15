<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportBatch extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'committed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function rollbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['preview', 'invalid', 'failed'], true);
    }
}
