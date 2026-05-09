<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();

        $transactions = Transaction::query()
            ->with(['account.client', 'type', 'user'])
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $loanStatuses = Loan::query()
            ->select('status', DB::raw('count(*) as total'), DB::raw('sum(principal_balance) as principal_balance'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $accountBalances = Account::query()
            ->with('accountType')
            ->select('account_type_id', DB::raw('count(*) as accounts_count'), DB::raw('sum(balance) as total_balance'))
            ->groupBy('account_type_id')
            ->get();

        $arrears = LoanSchedule::query()
            ->with('loan.client')
            ->whereDate('due_date', '<', now())
            ->where('outstanding_amount', '>', 0)
            ->orderBy('due_date')
            ->limit(30)
            ->get();

        $cashMovements = DrawerMovement::query()
            ->with(['session.drawer', 'session.user'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->latest()
            ->limit(30)
            ->get();

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'clients' => Client::count(),
                'active_accounts' => Account::where('status', 'active')->count(),
                'cash_in' => (clone $transactions)->whereHas('type', fn ($query) => $query->whereIn('direction', ['credit']))->sum('amount'),
                'cash_out' => (clone $transactions)->whereHas('type', fn ($query) => $query->whereIn('direction', ['withdrawal', 'debit']))->sum('amount'),
                'portfolio' => Loan::whereIn('status', ['approved', 'active', 'disbursed'])->sum('principal_balance'),
                'arrears' => LoanSchedule::whereDate('due_date', '<', now())->where('outstanding_amount', '>', 0)->sum('outstanding_amount'),
                'open_drawers' => DrawerSession::where('status', 'open')->count(),
                'journal_debits' => JournalEntry::where('entry_type', 'debit')->sum('amount'),
                'journal_credits' => JournalEntry::where('entry_type', 'credit')->sum('amount'),
            ],
            'cashTransactions' => (clone $transactions)->latest('posted_at')->limit(30)->get(),
            'nonCashTransactions' => Transaction::with(['account.client', 'type', 'user'])
                ->whereHas('type', fn ($query) => $query->where('affects_cash_drawer', false))
                ->latest('posted_at')
                ->limit(30)
                ->get(),
            'activeLoans' => Loan::with(['client', 'product'])->where('status', 'active')->latest()->limit(30)->get(),
            'loanStatuses' => $loanStatuses,
            'accountBalances' => $accountBalances,
            'arrears' => $arrears,
            'cashMovements' => $cashMovements,
            'journalEntries' => JournalEntry::with('chartAccount')->latest()->limit(30)->get(),
            'auditLogs' => AuditLog::with('user')->latest()->limit(25)->get(),
        ]);
    }
}
