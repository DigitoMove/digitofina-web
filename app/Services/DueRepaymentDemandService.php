<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DueRepaymentDemandService
{
    public function __construct(private LoanVisibilityService $visibility) {}

    /**
     * @return array{
     *     demands: Collection<int, array<string, mixed>>,
     *     summary: array<string, int|float>
     * }
     */
    public function build(User $user, CarbonImmutable $date, int $branchId = 0): array
    {
        $clientIds = $this->visibleLoans(Loan::query()->forOperationalListing(), $user, $branchId)
            ->whereHas('schedules', fn (Builder $query): Builder => $this->openSchedules($query)->whereDate('due_date', $date->toDateString()))
            ->pluck('client_id')
            ->unique()
            ->values();

        if ($clientIds->isEmpty()) {
            return [
                'demands' => collect(),
                'summary' => $this->summarize(collect()),
            ];
        }

        $schedules = LoanSchedule::query()
            ->with(['loan.client', 'loan.officer', 'loan.product'])
            ->excludingCancelledLoans()
            ->forActiveArrearsLoans()
            ->whereHas('loan', fn (Builder $query): Builder => $this->visibleLoans($query, $user, $branchId)->whereIn('client_id', $clientIds))
            ->tap(fn (Builder $query): Builder => $this->openSchedules($query))
            ->whereDate('due_date', '<=', $date->toDateString())
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();

        $demands = $schedules
            ->groupBy(fn (LoanSchedule $schedule): int => (int) $schedule->loan->client_id)
            ->map(fn (Collection $clientSchedules): array => $this->buildClientDemand($clientSchedules, $date))
            ->sortBy(fn (array $demand): string => (string) $demand['client']->full_name)
            ->values();

        return [
            'demands' => $demands,
            'summary' => $this->summarize($demands),
        ];
    }

    private function visibleLoans(Builder $query, User $user, int $branchId): Builder
    {
        $this->visibility->scopeRepaymentCalendar($query, $user);

        return $query->when($branchId, fn (Builder $query, int $branchId): Builder => $query->where('branch_id', $branchId));
    }

    private function openSchedules(Builder $query): Builder
    {
        return $query
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid');
    }

    /**
     * @param  Collection<int, LoanSchedule>  $clientSchedules
     * @return array<string, mixed>
     */
    private function buildClientDemand(Collection $clientSchedules, CarbonImmutable $date): array
    {
        $loans = $clientSchedules
            ->groupBy('loan_id')
            ->map(fn (Collection $loanSchedules): array => $this->buildLoanDemand($loanSchedules, $date))
            ->sortBy(fn (array $loanDemand): string => (string) $loanDemand['loan']->loan_number)
            ->values();

        return [
            'client' => $clientSchedules->first()->loan->client,
            'primary_loan' => $loans->first()['loan'] ?? null,
            'loans' => $loans,
            'due_today_amount' => (float) $loans->sum('due_today_amount'),
            'arrears_amount' => (float) $loans->sum('arrears_amount'),
            'total_amount' => (float) $loans->sum('total_amount'),
            'due_installments_count' => (int) $loans->sum('due_installments_count'),
            'arrears_installments_count' => (int) $loans->sum('arrears_installments_count'),
            'open_installments_count' => (int) $loans->sum('open_installments_count'),
        ];
    }

    /**
     * @param  Collection<int, LoanSchedule>  $loanSchedules
     * @return array<string, mixed>
     */
    private function buildLoanDemand(Collection $loanSchedules, CarbonImmutable $date): array
    {
        $todaySchedules = $loanSchedules->filter(fn (LoanSchedule $schedule): bool => $schedule->due_date?->toDateString() === $date->toDateString());
        $arrearsSchedules = $loanSchedules->filter(fn (LoanSchedule $schedule): bool => $schedule->due_date?->lt($date) === true);

        return [
            'loan' => $loanSchedules->first()->loan,
            'due_today_amount' => $this->sumOutstanding($todaySchedules),
            'arrears_amount' => $this->sumOutstanding($arrearsSchedules),
            'total_amount' => $this->sumOutstanding($loanSchedules),
            'due_installments_count' => $todaySchedules->count(),
            'arrears_installments_count' => $arrearsSchedules->count(),
            'open_installments_count' => $loanSchedules->count(),
            'schedules' => $loanSchedules->values(),
        ];
    }

    /**
     * @param  Collection<int, LoanSchedule>  $schedules
     */
    private function sumOutstanding(Collection $schedules): float
    {
        return (float) $schedules->sum(fn (LoanSchedule $schedule): float => (float) $schedule->outstanding_amount);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $demands
     * @return array<string, int|float>
     */
    private function summarize(Collection $demands): array
    {
        return [
            'client_count' => $demands->count(),
            'today_installment_count' => (int) $demands->sum('due_installments_count'),
            'arrears_installment_count' => (int) $demands->sum('arrears_installments_count'),
            'scheduled_count' => (int) $demands->sum('open_installments_count'),
            'today_amount' => (float) $demands->sum('due_today_amount'),
            'arrears_amount' => (float) $demands->sum('arrears_amount'),
            'total_amount' => (float) $demands->sum('total_amount'),
        ];
    }
}
