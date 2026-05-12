<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArrearsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(auth()->user()->canPerform('manage-loans') || auth()->user()->canPerform('view-reports'), 403);

        $schedules = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
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

        return view('arrears.index', ['schedules' => $schedules]);
    }

    public function applyPenalties(Request $request, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('manage-loans'), 403);

        $loan = $request->integer('loan_id') ? Loan::findOrFail($request->integer('loan_id')) : null;
        $count = $service->applyOverduePenalties($loan);

        return back()->with('status', "{$count} overdue installment(s) checked.");
    }
}
