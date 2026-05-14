<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ApiQuery
{
    /**
     * @param  array<int, string>  $searchable
     * @param  array<int, string>  $filterable
     * @param  array<int, string>  $sortable
     */
    public static function apply(
        Builder $query,
        Request $request,
        array $searchable = [],
        array $filterable = [],
        array $sortable = ['id', 'created_at', 'updated_at'],
        string $defaultSort = 'updated_at',
        string $defaultDirection = 'desc',
    ): Builder {
        self::applySearch($query, $request, $searchable);
        self::applyFilters($query, $request, $filterable);
        self::applyUpdatedSince($query, $request);
        self::applySorting($query, $request, $sortable, $defaultSort, $defaultDirection);

        return $query;
    }

    /**
     * @param  array<int, string>  $columns
     */
    public static function applySearch(Builder $query, Request $request, array $columns): Builder
    {
        $search = $request->string('search')->trim()->toString();

        if ($search === '' || $columns === []) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($columns, $search): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', "%{$search}%");
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    public static function applyFilters(Builder $query, Request $request, array $columns): Builder
    {
        $filters = $request->input('filter', []);
        $filters = is_array($filters) ? $filters : [];

        foreach ($columns as $column) {
            $value = $filters[$column] ?? $request->input($column);

            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($column, $value);

                continue;
            }

            $query->where($column, $value);
        }

        return $query;
    }

    public static function applyUpdatedSince(Builder $query, Request $request): Builder
    {
        $updatedSince = $request->string('updated_since')->trim()->toString();

        if ($updatedSince === '') {
            return $query;
        }

        return $query->where('updated_at', '>', Carbon::parse($updatedSince));
    }

    /**
     * @param  array<int, string>  $columns
     */
    public static function applySorting(
        Builder $query,
        Request $request,
        array $columns,
        string $defaultSort = 'updated_at',
        string $defaultDirection = 'desc',
    ): Builder {
        $requestedSort = $request->string('sort')->trim()->toString();
        $direction = strtolower($request->string('direction')->trim()->toString() ?: $defaultDirection);

        if (Str::startsWith($requestedSort, '-')) {
            $requestedSort = Str::after($requestedSort, '-');
            $direction = 'desc';
        }

        if ($requestedSort === '') {
            $requestedSort = $defaultSort;
        }

        if (! in_array($requestedSort, $columns, true)) {
            $requestedSort = $defaultSort;
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        return $query->orderBy($requestedSort, $direction);
    }

    public static function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        $perPage = $request->integer('per_page', $default);

        return max(1, min($perPage, $max));
    }

    public static function wantsDetailedResource(Request $request): bool
    {
        $view = $request->string('view')->trim()->lower()->toString();

        if (in_array($view, ['detail', 'detailed', 'full'], true)) {
            return true;
        }

        if (in_array($view, ['list', 'compact', 'summary'], true)) {
            return false;
        }

        $routeName = (string) $request->route()?->getName();

        return ! Str::endsWith($routeName, ['.index', '.logs']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(Request $request): array
    {
        return [
            'query' => [
                'search' => $request->string('search')->trim()->toString() ?: null,
                'filter' => $request->input('filter', []),
                'sort' => $request->string('sort')->trim()->toString() ?: null,
                'direction' => $request->string('direction')->trim()->toString() ?: null,
                'view' => self::wantsDetailedResource($request) ? 'detail' : 'list',
            ],
            'sync' => self::syncMeta($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function syncMeta(Request $request): array
    {
        return [
            'server_time' => now()->toIso8601String(),
            'requested_updated_since' => $request->string('updated_since')->trim()->toString() ?: null,
            'next_updated_since' => now()->toIso8601String(),
        ];
    }
}
