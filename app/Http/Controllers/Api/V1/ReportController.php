<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchPerformanceResource;
use App\Http\Resources\DrawerMovementResource;
use App\Http\Resources\LoanScheduleResource;
use App\Http\Resources\ReportExportResource;
use App\Jobs\GenerateReportExport;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return ApiResponse::data([
            'clients' => ApiSecurity::scopeTenant(Client::query(), $request->user())->count(),
            'active_clients' => ApiSecurity::scopeTenant(Client::query(), $request->user())->where('status', 'active')->count(),
            'total_savings' => (float) ApiSecurity::scopeTenant(SavingsAccount::query(), $request->user())->sum('current_balance'),
            'loan_portfolio' => (float) ApiSecurity::scopeTenant(Loan::query(), $request->user())->whereIn('status', ['approved', 'active', 'in_arrears'])->sum('principal_balance'),
            'arrears' => (float) LoanSchedule::whereHas('loan', fn ($query) => ApiSecurity::scopeTenant($query, $request->user()))->where('outstanding_amount', '>', 0)->whereDate('due_date', '<', now())->sum('outstanding_amount'),
            'deposits_today' => (float) ApiSecurity::scopeTenant(SavingsTransaction::query(), $request->user())->where('type', 'deposit')->whereDate('transaction_date', today())->sum('amount'),
            'withdrawals_today' => (float) ApiSecurity::scopeTenant(SavingsTransaction::query(), $request->user())->where('type', 'withdrawal')->whereDate('transaction_date', today())->sum('amount'),
            'branches' => ApiSecurity::scopeTenant(Branch::query(), $request->user())->count(),
        ], 'Dashboard summary retrieved.');
    }

    public function clients(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        $rows = ApiSecurity::scopeTenant(Client::query(), $request->user())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->map(fn (Client $client): array => ['status' => $client->status, 'total' => (int) $client->total]);

        return ApiResponse::data($rows, 'Client report retrieved.');
    }

    public function savings(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        $accountsByStatus = ApiSecurity::scopeTenant(SavingsAccount::query(), $request->user())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->map(fn (SavingsAccount $account): array => ['status' => $account->status, 'total' => (int) $account->total]);
        $transactionsByType = ApiSecurity::scopeTenant(SavingsTransaction::query(), $request->user())
            ->selectRaw('type, count(*) as total, sum(amount) as amount')
            ->groupBy('type')
            ->get()
            ->map(fn (SavingsTransaction $transaction): array => [
                'type' => $transaction->type,
                'total' => (int) $transaction->total,
                'amount' => (float) $transaction->amount,
            ]);

        return ApiResponse::data([
            'balances' => (float) ApiSecurity::scopeTenant(SavingsAccount::query(), $request->user())->sum('current_balance'),
            'accounts_by_status' => $accountsByStatus,
            'transactions_by_type' => $transactionsByType,
        ], 'Savings report retrieved.');
    }

    public function loans(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        $rows = ApiSecurity::scopeTenant(Loan::query(), $request->user())
            ->selectRaw('status, count(*) as total, sum(principal_balance) as principal')
            ->groupBy('status')
            ->get()
            ->map(fn (Loan $loan): array => [
                'status' => $loan->status,
                'total' => (int) $loan->total,
                'principal' => (float) $loan->principal,
            ]);

        return ApiResponse::data($rows, 'Loan report retrieved.');
    }

    public function arrears(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return ApiResponse::collection(
            LoanScheduleResource::collection(LoanSchedule::query()->with('loan.client')->whereHas('loan', fn ($query) => ApiSecurity::scopeTenant($query, $request->user()))->where('outstanding_amount', '>', 0)->whereDate('due_date', '<', now())->paginate($request->integer('per_page', 30))),
            'Arrears report retrieved.'
        );
    }

    public function tellers(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return ApiResponse::collection(
            DrawerMovementResource::collection(DrawerMovement::query()->with(['session.drawer', 'session.user'])->whereHas('session.drawer', fn ($query) => ApiSecurity::scopeTenant($query, $request->user()))->latest()->paginate($request->integer('per_page', 30))),
            'Teller report retrieved.'
        );
    }

    public function branchPerformance(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);

        return ApiResponse::collection(
            BranchPerformanceResource::collection(ApiSecurity::scopeTenant(Branch::query(), $request->user())->withCount(['clients'])->withSum('savingsAccounts', 'current_balance')->paginate($request->integer('per_page', 20))),
            'Branch performance retrieved.'
        );
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

        GenerateReportExport::dispatch($export)->afterCommit();

        return ApiResponse::resource(new ReportExportResource($export), 'Report export queued.', 202);
    }
}
