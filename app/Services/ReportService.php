<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    /**
     * @param  Builder<*>  $query
     * @param  array{date_from?:string,date_to?:string,branch_id?:int,status?:string}  $filters
     * @return Builder<*>
     */
    public function applyStandardFilters(Builder $query, array $filters, string $dateColumn = 'created_at'): Builder
    {
        return $query
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($dateColumn, '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($dateColumn, '<=', $date))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status));
    }
}
