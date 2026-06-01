<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class SystemSetting extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'string'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function value(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        $resolvedOrganizationId = $organizationId ?? static::resolvedOrganizationId();

        if ($resolvedOrganizationId !== null) {
            $organizationSetting = static::withoutGlobalScope('tenant')
                ->where('organization_id', $resolvedOrganizationId)
                ->where('key', $key)
                ->first();

            if ($organizationSetting !== null) {
                return $organizationSetting->value;
            }
        }

        return static::forPlatform()
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    /**
     * @return Builder<SystemSetting>
     */
    public static function forOrganization(?int $organizationId = null): Builder
    {
        return static::withoutGlobalScope('tenant')
            ->where('organization_id', $organizationId ?? static::resolvedOrganizationId());
    }

    /**
     * @return Builder<SystemSetting>
     */
    public static function forPlatform(): Builder
    {
        return static::withoutGlobalScope('tenant')->whereNull('organization_id');
    }

    public static function resolvedOrganizationId(): ?int
    {
        return app(TenantContext::class)->id()
            ?: Auth::user()?->organization_id;
    }

    public static function ensureForOrganization(Organization $organization): void
    {
        static::ensurePlatformDefaults();

        static::forPlatform()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->each(function (SystemSetting $template) use ($organization): void {
                static::withoutGlobalScope('tenant')->firstOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'key' => $template->key,
                    ],
                    $template->only(['label', 'value', 'type', 'group', 'description']),
                );
            });
    }

    public static function ensurePlatformDefaults(): void
    {
        if (static::forPlatform()->exists()) {
            return;
        }

        $templateOrganizationId = Organization::query()->oldest('id')->value('id');

        if ($templateOrganizationId === null) {
            return;
        }

        static::withoutGlobalScope('tenant')
            ->where('organization_id', $templateOrganizationId)
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->each(function (SystemSetting $template): void {
                static::withoutGlobalScope('tenant')->firstOrCreate(
                    [
                        'organization_id' => null,
                        'key' => $template->key,
                    ],
                    $template->only(['label', 'value', 'type', 'group', 'description']),
                );
            });
    }
}
