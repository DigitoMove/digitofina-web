<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
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
        $value = (string) $this->readableSecret($attribute);

        return $value === '' ? null : '••••••••'.substr($value, -4);
    }

    public function readableSecret(string $attribute): ?string
    {
        try {
            return $this->getAttribute($attribute);
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return array<int, string> */
    public function unreadableSecretAttributes(): array
    {
        $unreadable = [];

        foreach (['api_key', 'api_secret', 'webhook_secret'] as $attribute) {
            if (blank($this->getRawOriginal($attribute))) {
                continue;
            }

            try {
                $this->getAttribute($attribute);
            } catch (DecryptException) {
                $unreadable[] = $attribute;
            }
        }

        return $unreadable;
    }

    /** @param array<string, mixed> $attributes */
    public function saveCredentials(array $attributes): self
    {
        if (! $this->exists || $this->unreadableSecretAttributes() === []) {
            $this->fill($attributes)->save();

            return $this;
        }

        $replacement = new self;
        $replacement->exists = true;
        $replacement->setRawAttributes([$this->getKeyName() => $this->getKey()], true);
        $replacement->fill($attributes)->save();

        return $replacement;
    }
}
