<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalEntryResource;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    public function chartOfAccounts(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounting') || $request->user()->canPerform('view-reports'), 403);

        return response()->json(['data' => ChartAccount::query()->orderBy('code')->get()]);
    }

    public function journalEntries(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canPerform('manage-accounting') || $request->user()->canPerform('view-reports'), 403);

        return JournalEntryResource::collection(
            JournalEntry::query()->with('lines.chartAccount')->latest('journal_date')->paginate($request->integer('per_page', 30))
        );
    }

    public function trialBalance(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);

        $rows = JournalLine::query()
            ->join('chart_accounts', 'journal_lines.chart_account_id', '=', 'chart_accounts.id')
            ->select('chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type', DB::raw('sum(journal_lines.debit) as debit'), DB::raw('sum(journal_lines.credit) as credit'))
            ->groupBy('chart_accounts.id', 'chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type')
            ->orderBy('chart_accounts.code')
            ->get();

        return response()->json([
            'data' => $rows,
            'totals' => [
                'debit' => (float) $rows->sum('debit'),
                'credit' => (float) $rows->sum('credit'),
            ],
        ]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);

        $lines = JournalLine::query()
            ->with(['journalEntry', 'chartAccount'])
            ->when($request->integer('chart_account_id'), fn ($query, int $accountId) => $query->where('chart_account_id', $accountId))
            ->latest()
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($lines->items())->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'journal_number' => $line->journalEntry?->journal_number,
                'journal_date' => $line->journalEntry?->journal_date?->toDateString(),
                'account' => $line->chartAccount,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'narration' => $line->narration,
            ]),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'total' => $lines->total(),
            ],
        ]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);

        return response()->json(['data' => $this->statementRows(['income', 'expenses', 'expense'])]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-reports') || $request->user()->canPerform('manage-accounting'), 403);

        return response()->json(['data' => $this->statementRows(['assets', 'asset', 'liabilities', 'liability', 'equity'])]);
    }

    /**
     * @param  array<int, string>  $types
     */
    private function statementRows(array $types): array
    {
        return JournalLine::query()
            ->join('chart_accounts', 'journal_lines.chart_account_id', '=', 'chart_accounts.id')
            ->whereIn('chart_accounts.type', $types)
            ->select('chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type', DB::raw('sum(journal_lines.debit) as debit'), DB::raw('sum(journal_lines.credit) as credit'))
            ->groupBy('chart_accounts.id', 'chart_accounts.code', 'chart_accounts.name', 'chart_accounts.type')
            ->orderBy('chart_accounts.code')
            ->get()
            ->map(fn ($row) => $row->toArray() + ['balance' => (float) $row->credit - (float) $row->debit])
            ->all();
    }
}
