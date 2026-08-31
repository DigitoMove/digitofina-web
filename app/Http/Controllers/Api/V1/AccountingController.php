<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Resources\ChartAccountResource;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\AuditLogger;
use App\Services\FinancialControlService;
use App\Services\JournalEntryService;
use App\Services\TenantContext;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    public function chartOfAccounts(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounting') || $request->user()->canPerform('view-reports'), 403);
        $this->activeOrganizationId($request);

        $accounts = ApiQuery::apply(
            ApiSecurity::scopeTenant(ChartAccount::query(), $request->user()),
            $request,
            ['code', 'name'],
            ['type', 'is_active', 'is_cash_account'],
            ['id', 'code', 'name', 'type', 'created_at', 'updated_at'],
            'code',
            'asc',
        )->paginate(ApiQuery::perPage($request, 100));

        return ApiResponse::collection(ChartAccountResource::collection($accounts), 'Chart of accounts retrieved.', meta: ApiQuery::meta($request));
    }

    public function journalEntries(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounting') || $request->user()->canPerform('view-reports'), 403);
        $this->activeOrganizationId($request);

        $journals = ApiQuery::apply(
            ApiSecurity::scopeTenant(JournalEntry::query(), $request->user())->with('lines.chartAccount'),
            $request,
            ['journal_number', 'description', 'reference'],
            ['status', 'branch_id'],
            ['id', 'journal_number', 'journal_date', 'status', 'created_at', 'updated_at'],
            'journal_date',
        )->paginate(ApiQuery::perPage($request, 30));

        return ApiResponse::collection(JournalEntryResource::collection($journals), 'Journal entries retrieved.', meta: ApiQuery::meta($request));
    }

    public function storeJournalEntry(StoreJournalEntryRequest $request, JournalEntryService $journals, AuditLogger $auditLogger, FinancialControlService $controls): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: $request->user()->branch_id;

        if ($branchId) {
            $branch = Branch::findOrFail($branchId);
            ApiSecurity::assertTenantAccess($request->user(), $branch);
        }

        $amount = collect($request->validated('lines'))->sum(fn (array $line): float => (float) ($line['debit'] ?? 0));

        if ($controls->requiresMakerChecker($request->user(), 'journal_posting', $amount)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'journal_posting',
                subject: null,
                payload: [
                    'description' => $request->validated('description'),
                    'branch_id' => $branchId,
                    'lines' => $request->validated('lines'),
                ],
                amount: $amount,
                requiredPermission: 'post-journal-entries',
                reason: $request->validated('description'),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Journal entry submitted for maker-checker review.', 202);
        }

        $journal = $journals->post($request->validated('lines'), $request->validated('description'), $request->user(), $branchId);
        $auditLogger->record('accounting.journal_posted_api', $journal, [
            'journal_number' => $journal->journal_number,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::resource(new JournalEntryResource($journal->load('lines.chartAccount')), 'Journal entry posted successfully.', 201);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);
        $organizationId = $this->activeOrganizationId($request);

        $rows = JournalLine::query()
            ->join('chart_accounts', 'journal_lines.chart_account_id', '=', 'chart_accounts.id')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.organization_id', $organizationId)
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn ($query) => $query->where('journal_entries.branch_id', $request->user()->branch_id))
            ->select('chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type', DB::raw('sum(journal_lines.debit) as debit'), DB::raw('sum(journal_lines.credit) as credit'))
            ->groupBy('chart_accounts.id', 'chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type')
            ->orderBy('chart_accounts.code')
            ->get();

        return ApiResponse::data([
            'rows' => $rows->map(fn ($row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'debit' => (float) $row->debit,
                'credit' => (float) $row->credit,
            ]),
            'totals' => [
                'debit' => (float) $rows->sum('debit'),
                'credit' => (float) $rows->sum('credit'),
            ],
        ], 'Trial balance retrieved.');
    }

    public function generalLedger(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);
        $this->activeOrganizationId($request);

        $linesQuery = JournalLine::query()
            ->with(['journalEntry', 'chartAccount'])
            ->whereHas('journalEntry', fn ($query) => ApiSecurity::scopeTenant($query, $request->user()))
            ->when($request->integer('chart_account_id'), fn ($query, int $accountId) => $query->where('chart_account_id', $accountId));

        $lines = ApiQuery::apply(
            $linesQuery,
            $request,
            ['narration'],
            ['chart_account_id'],
            ['id', 'chart_account_id', 'created_at', 'updated_at'],
            'created_at',
        )->paginate(ApiQuery::perPage($request, 50));

        return ApiResponse::data([
            'lines' => collect($lines->items())->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'journal_number' => $line->journalEntry?->journal_number,
                'journal_date' => $line->journalEntry?->journal_date?->toDateString(),
                'account' => $line->chartAccount ? (new ChartAccountResource($line->chartAccount))->resolve($request) : null,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'narration' => $line->narration,
            ]),
        ], 'General ledger retrieved.', meta: [
            'query' => ApiQuery::meta($request)['query'],
            'sync' => ApiQuery::syncMeta($request),
            'pagination' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'total' => $lines->total(),
            ],
        ]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);

        return ApiResponse::data($this->statementRows(['income', 'expenses', 'expense'], $request), 'Income statement retrieved.');
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);

        return ApiResponse::data($this->statementRows(['assets', 'asset', 'liabilities', 'liability', 'equity'], $request), 'Balance sheet retrieved.');
    }

    /**
     * @param  array<int, string>  $types
     */
    private function statementRows(array $types, Request $request): array
    {
        $organizationId = $this->activeOrganizationId($request);

        return JournalLine::query()
            ->join('chart_accounts', 'journal_lines.chart_account_id', '=', 'chart_accounts.id')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.organization_id', $organizationId)
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn ($query) => $query->where('journal_entries.branch_id', $request->user()->branch_id))
            ->whereIn('chart_accounts.type', $types)
            ->select('chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type', DB::raw('sum(journal_lines.debit) as debit'), DB::raw('sum(journal_lines.credit) as credit'))
            ->groupBy('chart_accounts.id', 'chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type')
            ->orderBy('chart_accounts.code')
            ->get()
            ->map(fn ($row) => $row->toArray() + ['balance' => (float) $row->credit - (float) $row->debit])
            ->all();
    }

    private function activeOrganizationId(Request $request): int
    {
        $organizationId = app(TenantContext::class)->id() ?: $request->user()->organization_id;

        abort_unless($organizationId, 422, 'Select an active organization before generating a report.');

        return (int) $organizationId;
    }
}
