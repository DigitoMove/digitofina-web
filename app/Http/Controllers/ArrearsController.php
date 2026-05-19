<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\BusinessCalendarService;
use App\Services\LoanService;
use App\Services\LoanVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArrearsController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility, BusinessCalendarService $calendar): View
    {
        abort_unless(auth()->user()->canPerform('manage-loans') || auth()->user()->canPerform('view-reports'), 403);

        $schedules = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
            ->whereHas('loan', fn (Builder $query): Builder => $visibility->scope($query, $request->user()))
            ->where('outstanding_amount', '>', 0)
            ->where(function ($query): void {
                $query->where('status', 'overdue')
                    ->orWhereDate('due_date', '<', now()->toDateString());
            })
            ->when($request->string('bucket')->toString(), function ($query, string $bucket): void {
                match ($bucket) {
                    '1-30' => $query->whereBetween('days_overdue', [1, 30]),
                    '31-60' => $query->whereBetween('days_overdue', [31, 60]),
                    '61-90' => $query->whereBetween('days_overdue', [61, 90]),
                    '91-180' => $query->whereBetween('days_overdue', [91, 180]),
                    '180+' => $query->where('days_overdue', '>', 180),
                    default => null,
                };
            })
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString();

        $loanArrears = LoanSchedule::query()
            ->whereIn('loan_id', $schedules->getCollection()->pluck('loan_id')->unique())
            ->where('outstanding_amount', '>', 0)
            ->whereDate('due_date', '<', now()->toDateString())
            ->selectRaw('loan_id, sum(outstanding_amount) as cumulative_due, max(days_overdue) as stored_days_overdue')
            ->groupBy('loan_id')
            ->get()
            ->keyBy('loan_id');

        return view('arrears.index', [
            'schedules' => $schedules,
            'loanArrears' => $loanArrears,
            'calendar' => $calendar,
        ]);
    }

    public function applyPenalties(Request $request, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('manage-loans'), 403);

        $loan = $request->integer('loan_id') ? Loan::findOrFail($request->integer('loan_id')) : null;
        $count = $service->applyOverduePenalties($loan);

        return back()->with('status', "{$count} overdue installment(s) checked.");
    }
}
