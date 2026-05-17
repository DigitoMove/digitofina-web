<?php

namespace App\Http\Controllers;

use App\Models\LoanSchedule;
use App\Services\LoanVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DueRepaymentController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): View
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('post-repayments') || $request->user()->canPerform('view-reports'), 403);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);
        $date = CarbonImmutable::parse($data['date'] ?? today()->toDateString());
        $schedules = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
            ->whereHas('loan', fn (Builder $query): Builder => $visibility->scope($query, $request->user()))
            ->where('outstanding_amount', '>', 0)
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', $date->toDateString())
            ->orderBy('due_date')
            ->paginate(25)
            ->withQueryString();

        return view('due-repayments.index', [
            'date' => $date,
            'schedules' => $schedules,
            'totalAmount' => (float) $schedules->getCollection()->sum('outstanding_amount'),
        ]);
    }
}
