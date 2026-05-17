<?php

namespace App\Http\Controllers;

use App\Jobs\RunEndOfDayProcess;
use App\Models\Branch;
use App\Models\BranchCashReconciliation;
use App\Models\BranchDailySummary;
use App\Models\DailyAccountingSummary;
use App\Services\EndOfDayService;
use App\Support\CurrencyDenomination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class EndOfDayController extends Controller
{
    public function index(Request $request, EndOfDayService $endOfDay): View
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-drawers'), 403);

        $businessDate = $request->date('business_date') ?: today();
        $branch = Branch::query()->find($request->integer('branch_id')) ?: $request->user()->branch;
        abort_unless($branch, 404);

        if (! $request->user()->canPerform('manage-branches')) {
            abort_unless((int) $branch->id === (int) $request->user()->branch_id, 403);
        }

        $financialDay = $endOfDay->getOrOpenFinancialDay($branch, $businessDate, $request->user());
        $summary = BranchDailySummary::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate->toDateString())
            ->first();
        $reconciliation = BranchCashReconciliation::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate->toDateString())
            ->first();
        $accountingSummaries = DailyAccountingSummary::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate->toDateString())
            ->orderBy('account_code')
            ->get();

        return view('end-of-day.index', [
            'branches' => Branch::query()->orderBy('name')->get(),
            'branch' => $branch,
            'businessDate' => $businessDate,
            'financialDay' => $financialDay,
            'summary' => $summary,
            'reconciliation' => $reconciliation,
            'accountingSummaries' => $accountingSummaries,
        ]);
    }

    public function reconcile(Request $request, EndOfDayService $endOfDay): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-drawers') || $request->user()->canPerform('manage-accounting'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
            'counted_cash' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $endOfDay->reconcile($this->authorizedBranch($request, (int) $data['branch_id']), $request->user(), $data['business_date'], (float) $data['counted_cash'], $data['notes'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['counted_cash' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Branch cash reconciled.');
    }

    public function process(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-accounting') || $request->user()->canPerform('manage-drawers'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
        ]);

        $branch = $this->authorizedBranch($request, (int) $data['branch_id']);
        RunEndOfDayProcess::dispatch($branch->id, $data['business_date'], $request->user()->id)->afterCommit();

        return back()->with('status', 'End-of-day processing queued.');
    }

    public function close(Request $request, EndOfDayService $endOfDay): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-accounting') || $request->user()->canPerform('manage-drawers'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
            'counted_cash' => ['required', 'numeric', 'min:0', CurrencyDenomination::validationRule()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $endOfDay->close($this->authorizedBranch($request, (int) $data['branch_id']), $request->user(), $data['business_date'], (float) $data['counted_cash'], $data['notes'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['counted_cash' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Business day closed and processing completed.');
    }

    public function lock(Request $request, EndOfDayService $endOfDay): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-end-of-day') || $request->user()->canPerform('manage-accounting') || $request->user()->canPerform('audit-system'), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
        ]);

        try {
            $endOfDay->lock($this->authorizedBranch($request, (int) $data['branch_id']), $request->user(), $data['business_date']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['business_date' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Business day locked.');
    }

    private function authorizedBranch(Request $request, int $branchId): Branch
    {
        $branch = Branch::query()->findOrFail($branchId);

        if (! $request->user()->canPerform('manage-branches')) {
            abort_unless((int) $branch->id === (int) $request->user()->branch_id, 403);
        }

        return $branch;
    }
}
