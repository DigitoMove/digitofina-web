<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (Auth::user()->isMember()) {
            return redirect()->route('member.dashboard');
        }

        $depositType = TransactionType::where('code', 'deposit')->first();
        $withdrawalType = TransactionType::where('code', 'withdrawal')->first();
        $user = Auth::user();
        $drawer = $user->drawerSessions()->with('drawer')->where('status', 'open')->latest()->first()?->drawer;

        return view('dashboard.index', [
            'totalClients' => Client::count(),
            'activeAccounts' => Account::where('status', 'active')->count(),
            'totalSavings' => Account::whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->sum('balance'),
            'totalLoans' => Loan::whereIn('status', ['approved', 'active'])->sum('principal_balance'),
            'todayDeposits' => $depositType ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $depositType->id)->sum('amount') : 0,
            'todayWithdrawals' => $withdrawalType ? Transaction::whereDate('posted_at', today())->where('transaction_type_id', $withdrawalType->id)->sum('amount') : 0,
            'drawerBalance' => $drawer?->current_balance ?? 0,
            'pendingApprovals' => Approval::where('status', 'pending')->count(),
            'branchCount' => Branch::count(),
            'staffCount' => User::where('status', 'active')->count(),
            'auditEventsToday' => AuditLog::whereDate('created_at', today())->count(),
            'alerts' => [
                'Loans in arrears' => Loan::where('status', 'defaulted')->count(),
                'Open drawer' => $drawer ? $drawer->name : 'No active teller drawer',
                'Organization' => $user->organization?->name ?? 'Not assigned',
            ],
        ]);
    }
}
