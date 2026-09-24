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
use App\Models\FixedSavingsAccount;
use App\Models\GroupSavingsContribution;
use App\Models\InvestmentAccount;
use App\Models\InvestmentPlan;
use App\Models\InvestmentTransaction;
use App\Models\InvestorAccount;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Services\LoanVisibilityService;
use App\Services\ModuleService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function dashboard(Request $request, LoanVisibilityService $visibility): JsonResponse
    {
        $this->authorizeReportAccess($request);
        $moduleEnabled = fn (string $module): bool => $this->moduleEnabled($request, $module);

        return ApiResponse::data(array_filter([
            'clients' => ApiSecurity::scopeTenant(Client::query(), $request->user())->count(),
            'active_clients' => ApiSecurity::scopeTenant(Client::query(), $request->user())->where('status', 'active')->count(),
            'total_savings' => $moduleEnabled('savings') ? (float) ApiSecurity::scopeTenant(SavingsAccount::query(), $request->user())->sum('current_balance') : null,
            'loan_portfolio' => $moduleEnabled('loans') ? (float) $visibility->scope(ApiSecurity::scopeTenant(Loan::query(), $request->user()), $request->user())->whereIn('status', ['approved', 'active', 'in_arrears'])->sum('principal_balance') : null,
            'arrears' => $moduleEnabled('loans') ? (float) LoanSchedule::query()->excludingCancelledLoans()->eligibleForArrears(now())->whereHas('loan', fn ($query) => $visibility->scopeRepaymentCalendar(ApiSecurity::scopeTenant($query, $request->user()), $request->user()))->sum('outstanding_amount') : null,
            'deposits_today' => $moduleEnabled('savings') ? (float) ApiSecurity::scopeTenant(SavingsTransaction::query(), $request->user())->where('type', 'deposit')->whereDate('transaction_date', today())->sum('amount') : null,
            'withdrawals_today' => $moduleEnabled('savings') ? (float) ApiSecurity::scopeTenant(SavingsTransaction::query(), $request->user())->where('type', 'withdrawal')->whereDate('transaction_date', today())->sum('amount') : null,
            'total_investors' => $moduleEnabled('investments') ? ApiSecurity::scopeTenant(InvestorAccount::query(), $request->user())->where('status', 'active')->count() : null,
            'active_investments' => $moduleEnabled('investments') ? ApiSecurity::scopeTenant(InvestmentAccount::query(), $request->user())->where('status', 'active')->count() : null,
            'investment_balance' => $moduleEnabled('investments') ? (float) ApiSecurity::scopeTenant(InvestorAccount::query(), $request->user())->sum('current_investment_balance') : null,
            'returns_posted' => $moduleEnabled('investments') ? (float) ApiSecurity::scopeTenant(InvestmentTransaction::query(), $request->user())->where('type', 'return_posting')->sum('amount') : null,
            'branches' => ApiSecurity::scopeTenant(Branch::query(), $request->user())->count(),
        ], fn (mixed $value): bool => $value !== null), 'Dashboard summary retrieved.');
    }

    public function clients(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);
        $this->requireModule($request, 'savings');

        $rows = ApiSecurity::scopeTenant(Client::query(), $request->user())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->map(fn (Client $client): array => ['status' => $client->status, 'total' => (int) $client->total]);

        return ApiResponse::data($rows, 'Client report retrieved.');
    }

    public function savings(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);
        $this->requireModule($request, 'savings');

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

    public function savingsAnalytics(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);
        $this->requireModule($request, 'loans');

        $monthStart = today()->startOfMonth();
        $monthEnd = today()->endOfMonth();

        $goals = ApiSecurity::scopeTenant(SavingsGoal::query(), $request->user());
        $fixedSavings = ApiSecurity::scopeTenant(FixedSavingsAccount::query(), $request->user());
        $savingsAccounts = ApiSecurity::scopeTenant(SavingsAccount::query(), $request->user());
        $transactions = ApiSecurity::scopeTenant(SavingsTransaction::query(), $request->user());

        return ApiResponse::data([
            'goal_progress_report' => (clone $goals)
                ->selectRaw('status, count(*) as goals, coalesce(sum(target_amount), 0) as target_amount, coalesce(sum(current_saved_amount), 0) as saved_amount')
                ->groupBy('status')
                ->get(),
            'fixed_savings_maturity_report' => (clone $fixedSavings)
                ->whereIn('status', ['active', 'matured'])
                ->orderBy('maturity_date')
                ->limit(30)
                ->get(['account_number', 'client_id', 'principal_amount', 'maturity_date', 'expected_interest', 'status']),
            'group_savings_contribution_report' => ApiSecurity::scopeTenant(GroupSavingsContribution::query(), $request->user())
                ->selectRaw('group_savings_account_id, count(*) as contributions, coalesce(sum(amount), 0) as amount')
                ->groupBy('group_savings_account_id')
                ->get(),
            'member_contribution_report' => ApiSecurity::scopeTenant(GroupSavingsContribution::query(), $request->user())
                ->selectRaw('client_id, count(*) as contributions, coalesce(sum(amount), 0) as amount')
                ->groupBy('client_id')
                ->orderByDesc('amount')
                ->limit(30)
                ->get(),
            'savings_growth_report' => [
                'deposits_this_month' => (float) (clone $transactions)->whereIn('type', ['deposit', 'transfer_in'])->whereBetween('transaction_date', [$monthStart, $monthEnd])->sum('amount'),
                'withdrawals_this_month' => (float) (clone $transactions)->whereIn('type', ['withdrawal', 'transfer_out'])->whereBetween('transaction_date', [$monthStart, $monthEnd])->sum('amount'),
            ],
            'dormant_savings_report' => (clone $savingsAccounts)
                ->where(function ($query): void {
                    $query->where('status', 'dormant')
                        ->orWhereDate('last_activity_at', '<=', today()->subMonths(6));
                })
                ->count(),
            'savings_withdrawal_report' => (float) (clone $transactions)->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount'),
        ], 'Savings analytics report retrieved.');
    }

    public function loans(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);
        $this->requireModule($request, 'loans');

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

    public function arrears(Request $request, LoanVisibilityService $visibility): JsonResponse
    {
        $this->authorizeReportAccess($request);
        abort_if($request->user()->isTellerCashier(), 403);
        $this->requireModule($request, 'loans');

        return ApiResponse::collection(
            LoanScheduleResource::collection(LoanSchedule::query()->with('loan.client')->excludingCancelledLoans()->eligibleForArrears(now())->whereHas('loan', fn ($query) => $visibility->scopeRepaymentCalendar(ApiSecurity::scopeTenant($query, $request->user()), $request->user()))->paginate($request->integer('per_page', 30))),
            'Arrears report retrieved.'
        );
    }

    public function tellers(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);
        $this->requireModule($request, 'investments');

        return ApiResponse::collection(
            DrawerMovementResource::collection(DrawerMovement::query()->with(['session.drawer', 'session.user'])->whereHas('session.drawer', fn ($query) => ApiSecurity::scopeTenant($query, $request->user()))->latest()->paginate($request->integer('per_page', 30))),
            'Teller report retrieved.'
        );
    }

    public function branchPerformance(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);

        return ApiResponse::collection(
            BranchPerformanceResource::collection(ApiSecurity::scopeTenant(Branch::query(), $request->user())->withCount(['clients'])->withSum('savingsAccounts', 'current_balance')->paginate($request->integer('per_page', 20))),
            'Branch performance retrieved.'
        );
    }

    public function investments(Request $request): JsonResponse
    {
        $this->authorizeReportAccess($request);

        $accounts = ApiSecurity::scopeTenant(InvestmentAccount::query(), $request->user());
        $transactions = ApiSecurity::scopeTenant(InvestmentTransaction::query(), $request->user());

        $plans = ApiSecurity::scopeTenant(InvestmentPlan::query(), $request->user())
            ->withCount(['investmentAccounts'])
            ->withSum('investmentAccounts', 'current_balance')
            ->orderByDesc('investment_accounts_sum_current_balance')
            ->limit(10)
            ->get()
            ->map(fn (InvestmentPlan $plan): array => [
                'plan' => $plan->name,
                'code' => $plan->code,
                'investment_accounts' => (int) $plan->investment_accounts_count,
                'balance' => (float) $plan->investment_accounts_sum_current_balance,
            ]);

        $byBranch = ApiSecurity::scopeTenant(InvestmentAccount::query(), $request->user())
            ->selectRaw('branch_id, count(*) as total, coalesce(sum(current_balance), 0) as balance, coalesce(sum(actual_return_amount), 0) as returns')
            ->groupBy('branch_id')
            ->get();

        $byOfficer = ApiSecurity::scopeTenant(InvestorAccount::query(), $request->user())
            ->selectRaw('assigned_officer_id, count(*) as investors, coalesce(sum(current_investment_balance), 0) as balance')
            ->groupBy('assigned_officer_id')
            ->get();

        return ApiResponse::data([
            'active_investments' => (clone $accounts)->where('status', 'active')->count(),
            'matured_investments' => (clone $accounts)->where('status', 'matured')->count(),
            'investment_contributions' => (float) (clone $transactions)->whereIn('type', ['new_investment_deposit', 'top_up'])->sum('amount'),
            'investment_withdrawals' => (float) (clone $transactions)->where('type', 'withdrawal')->sum('amount'),
            'returns_earned' => (float) (clone $transactions)->where('type', 'return_posting')->sum('amount'),
            'expected_returns' => (float) (clone $accounts)->sum('expected_return_amount'),
            'actual_returns' => (float) (clone $accounts)->sum('actual_return_amount'),
            'plan_performance' => $plans,
            'branch_performance' => $byBranch,
            'officer_performance' => $byOfficer,
        ], 'Investment report retrieved.');
    }

    public function export(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('export-reports'), 403);
        $organizationId = $this->activeOrganizationId($request);

        $data = $request->validate([
            'report_type' => ['required', 'string', 'max:80'],
            'format' => ['required', 'in:pdf,xlsx,csv'],
            'filters' => ['nullable', 'array'],
        ]);

        $export = ReportExport::create([
            'organization_id' => $organizationId,
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

    public function downloadExport(Request $request, ReportExport $reportExport): StreamedResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $reportExport);
        abort_unless($reportExport->status === 'completed' && filled($reportExport->path), 404, 'This export is not ready.');

        $disk = Storage::disk($reportExport->disk ?? 'local');
        abort_unless($disk->exists($reportExport->path), 404, 'The export file is no longer available.');

        return $disk->download($reportExport->path, basename($reportExport->path));
    }

    private function moduleEnabled(Request $request, string $module): bool
    {
        $user = $request->user();

        return $user->hasRole('super-admin')
            || ! $user->organization
            || app(ModuleService::class)->isEnabled($user->organization, $module);
    }

    private function requireModule(Request $request, string $module): void
    {
        abort_unless($this->moduleEnabled($request, $module), 403, 'This module is disabled for your organization.');
    }

    private function authorizeReportAccess(Request $request): void
    {
        abort_unless($request->user()->canPerform('view-reports'), 403);
        $this->activeOrganizationId($request);
    }

    private function activeOrganizationId(Request $request): int
    {
        $organizationId = app(TenantContext::class)->id() ?: $request->user()->organization_id;

        abort_unless($organizationId, 422, 'Select an active organization before generating a report.');

        if ($request->user()->hasGlobalOrganizationAccess() && app(TenantContext::class)->id() === null) {
            app(TenantContext::class)->set($request->user()->organization, true);
        }

        return (int) $organizationId;
    }
}
