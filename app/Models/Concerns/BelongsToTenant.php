<?php

namespace App\Models\Concerns;

use App\Services\TenantContext;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $model = $query->getModel();
            $organizationId = app(TenantContext::class)->id() ?: Auth::user()?->organization_id;
            $user = Auth::user();

            if ($organizationId && ! $user?->hasRole('super-admin') && self::tenantModelHasColumn($model, 'organization_id')) {
                $query->where($model->qualifyColumn('organization_id'), $organizationId);
            }

            if ($user && ! ApiSecurity::hasGlobalBranchAccess($user) && self::tenantModelHasColumn($model, 'branch_id')) {
                $query->where(function (Builder $query) use ($model, $user): void {
                    $query->where($model->qualifyColumn('branch_id'), $user->branch_id)
                        ->orWhereNull($model->qualifyColumn('branch_id'));
                });
            }
        });

        static::creating(function (Model $model): void {
            $organizationId = app(TenantContext::class)->id() ?: Auth::user()?->organization_id;

            if ($organizationId && self::tenantModelHasColumn($model, 'organization_id') && blank($model->getAttribute('organization_id'))) {
                $model->setAttribute('organization_id', $organizationId);
            }

            if (Auth::user()?->branch_id && self::tenantModelHasColumn($model, 'branch_id') && blank($model->getAttribute('branch_id'))) {
                $model->setAttribute('branch_id', Auth::user()->branch_id);
            }
        });
    }

    private static function tenantModelHasColumn(Model $model, string $column): bool
    {
        static $columns = [];

        $table = $model->getTable();
        $key = "{$table}.{$column}";

        return $columns[$key] ??= Schema::hasColumn($table, $column);
    }
}
