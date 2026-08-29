<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NylonPayCredential extends Model
{
    protected $fillable = ['organization_id', 'scope_key', 'profile', 'api_key', 'api_secret', 'webhook_secret', 'webhook_token', 'base_url', 'updated_by'];

    protected $hidden = ['api_key', 'api_secret', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function masked(string $attribute): ?string
    {
        $value = (string) $this->getAttribute($attribute);

        return $value === '' ? null : '••••••••'.substr($value, -4);
    }
}
