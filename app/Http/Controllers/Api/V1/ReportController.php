<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => [
            'clients' => Client::count(),
            'active_clients' => Client::where('status', 'active')->count(),
            'total_savings' => (float) SavingsAccount::sum('current_balance'),
            'loan_portfolio' => (float) Loan::whereIn('status', ['approved', 'active', 'in_arrears'])->sum('principal_balance'),
            'arrears' => (float) LoanSchedule::where('outstanding_amount', '>', 0)->whereDate('due_date', '<', now())->sum('outstanding_amount'),
            'deposits_today' => (float) SavingsTransaction::where('type', 'deposit')->whereDate('transaction_date', today())->sum('amount'),
            'withdrawals_today' => (float) SavingsTransaction::where('type', 'withdrawal')->whereDate('transaction_date', today())->sum('amount'),
            'branches' => Branch::count(),
        ]]);
    }

    public function clients(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => Client::query()->selectRaw('status, count(*) as total')->groupBy('status')->get()]);
    }

    public function savings(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => [
            'balances' => (float) SavingsAccount::sum('current_balance'),
            'accounts_by_status' => SavingsAccount::query()->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'transactions_by_type' => SavingsTransaction::query()->selectRaw('type, count(*) as total, sum(amount) as amount')->groupBy('type')->get(),
        ]]);
    }

    public function loans(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => Loan::query()->selectRaw('status, count(*) as total, sum(principal_balance) as principal')->groupBy('status')->get()]);
    }

    public function arrears(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => LoanSchedule::query()->with('loan.client')->where('outstanding_amount', '>', 0)->whereDate('due_date', '<', now())->paginate($request->integer('per_page', 30))]);
    }

    public function tellers(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => DrawerMovement::query()->with(['session.drawer', 'user'])->latest()->paginate($request->integer('per_page', 30))]);
    }

    public function branchPerformance(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => Branch::query()->withCount(['clients'])->withSum('savingsAccounts', 'current_balance')->paginate($request->integer('per_page', 20))]);
    }

    public function export(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('export-reports'), 403);

        $data = $request->validate([
            'report_type' => ['required', 'string', 'max:80'],
            'format' => ['required', 'in:pdf,xlsx,csv'],
            'filters' => ['nullable', 'array'],
        ]);

        $export = ReportExport::create([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $request->user()->branch_id,
            'requested_by' => $request->user()->id,
            'report_type' => $data['report_type'],
            'format' => $data['format'],
            'filters' => $data['filters'] ?? [],
            'status' => 'queued',
        ]);

        return response()->json(['data' => $export], 202);
    }
}
