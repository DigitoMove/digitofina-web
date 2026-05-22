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
        return static::forOrganization($organizationId)
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

    public static function resolvedOrganizationId(): ?int
    {
        return app(TenantContext::class)->id()
            ?: Auth::user()?->organization_id
            ?: Organization::query()->oldest('id')->value('id');
    }

    public static function ensureForOrganization(Organization $organization): void
    {
        $templateOrganizationId = static::templateOrganizationId($organization);

        static::withoutGlobalScope('tenant')
            ->when(
                $templateOrganizationId,
                fn (Builder $query): Builder => $query->where('organization_id', $templateOrganizationId),
                fn (Builder $query): Builder => $query->whereNull('organization_id'),
            )
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

    private static function templateOrganizationId(Organization $organization): ?int
    {
        $originalOrganizationId = Organization::query()
            ->whereKeyNot($organization->id)
            ->oldest('id')
            ->value('id');

        if ($originalOrganizationId && static::withoutGlobalScope('tenant')->where('organization_id', $originalOrganizationId)->exists()) {
            return (int) $originalOrganizationId;
        }

        return null;
    }
}
