<?php

namespace App\Models;

use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAssignableToOrganization(Builder $query, ?int $organizationId): Builder
    {
        return $query
            ->where('slug', '!=', RoleCatalog::SUPER_ADMIN_SLUG)
            ->where(function (Builder $query) use ($organizationId): void {
                $query->whereNull('organization_id')
                    ->orWhereIn('slug', RoleCatalog::ASSIGNABLE_SLUGS);

                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            });
    }

    public function isAssignableToOrganization(?int $organizationId): bool
    {
        if ($this->slug === RoleCatalog::SUPER_ADMIN_SLUG) {
            return false;
        }

        return $this->organization_id === null
            || in_array($this->slug, RoleCatalog::ASSIGNABLE_SLUGS, true)
            || ($organizationId !== null && (int) $this->organization_id === $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
