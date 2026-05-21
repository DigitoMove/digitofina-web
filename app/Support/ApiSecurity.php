<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ApiSecurity
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function scopeTenant(Builder $query, User $user): Builder
    {
        $model = $query->getModel();
        $tenantOrganizationId = app(TenantContext::class)->id();

        if (self::hasColumn($model, 'organization_id')) {
            if ($tenantOrganizationId !== null) {
                $query->where($model->qualifyColumn('organization_id'), $tenantOrganizationId);
            } elseif (! $user->hasGlobalOrganizationAccess() && $user->organization_id !== null) {
                $query->where($model->qualifyColumn('organization_id'), $user->organization_id);
            }
        }

        if ($model instanceof Branch && ! self::hasGlobalBranchAccess($user)) {
            $query->whereKey($user->branch_id);
        }

        if (! self::hasGlobalBranchAccess($user) && self::hasColumn($model, 'branch_id')) {
            $query->where($model->qualifyColumn('branch_id'), $user->branch_id);
        }

        return $query;
    }

    public static function assertTenantAccess(User $user, Model $model): void
    {
        abort_unless(self::canAccessTenant($user, $model), 403);
    }

    public static function canAccessTenant(User $user, Model $model): bool
    {
        if (self::hasColumn($model, 'organization_id') && $model->getAttribute('organization_id') !== null) {
            $tenantOrganizationId = app(TenantContext::class)->id();

            if ($tenantOrganizationId !== null && (int) $model->getAttribute('organization_id') !== (int) $tenantOrganizationId) {
                return false;
            }

            if (! $user->hasGlobalOrganizationAccess() && $user->organization_id !== null && (int) $model->getAttribute('organization_id') !== (int) $user->organization_id) {
                return false;
            }
        }

        if (self::hasColumn($model, 'branch_id') && ! self::hasGlobalBranchAccess($user) && $model->getAttribute('branch_id') !== null && (int) $model->getAttribute('branch_id') !== (int) $user->branch_id) {
            return false;
        }

        if ($model instanceof Branch && ! self::hasGlobalBranchAccess($user) && (int) $model->getKey() !== (int) $user->branch_id) {
            return false;
        }

        return true;
    }

    public static function assertPermission(User $user, string $permission): void
    {
        abort_unless($user->canPerform($permission), 403);
    }

    public static function hasGlobalBranchAccess(User $user): bool
    {
        return $user->branch_id === null
            || $user->hasOrganizationBranchAccess()
            || $user->canPerform('audit-system');
    }

    public static function assertClientBranchAccess(User $user, int $branchId): void
    {
        abort_unless(self::hasGlobalBranchAccess($user) || $user->branch_id === $branchId, 403);
    }

    private static function hasColumn(Model $model, string $column): bool
    {
        static $columns = [];

        $table = $model->getTable();
        $key = "{$table}.{$column}";

        return $columns[$key] ??= Schema::hasColumn($table, $column);
    }
}
