<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MemberPortalController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        $client = Client::query()
            ->with([
                'accounts.accountType',
                'accounts.transactions.type',
                'savingsAccounts.product',
                'savingsAccounts.transactions',
                'investmentAccounts.plan',
                'investmentAccounts.transactions',
                'loans.product',
                'loans.schedules',
            ])
            ->where('user_id', Auth::id())
            ->first();

        if (! $client) {
            return redirect()->route('dashboard')->withErrors(['member' => 'No member profile is linked to this login yet.']);
        }

        $savingsAccounts = $client->savingsAccounts;
        $investmentAccounts = $client->investmentAccounts;
        $legacySavingsTotal = $client->accounts->where('accountType.category', 'savings')->sum(fn ($account) => (float) $account->balance);
        $investmentGrowth = $savingsAccounts->sum(fn ($account): float => (float) $account->transactions
            ->where('type', 'interest_posting')
            ->where('status', 'successful')
            ->sum('amount'));
        $investmentTransactions = $savingsAccounts
            ->flatMap->transactions
            ->sortByDesc('transaction_date')
            ->take(12);

        return view('member.dashboard', [
            'client' => $client,
            'savingsAccounts' => $savingsAccounts,
            'investmentAccounts' => $investmentAccounts,
            'savingsTotal' => ($savingsAccounts->sum(fn ($account): float => (float) $account->current_balance) + $investmentAccounts->sum(fn ($account): float => (float) $account->current_balance)) ?: $legacySavingsTotal,
            'investmentGrowth' => $investmentGrowth,
            'loanBalance' => $client->loans->sum(fn ($loan) => (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance),
            'transactions' => $investmentTransactions->isNotEmpty() ? $investmentTransactions : $client->accounts->flatMap->transactions->sortByDesc('posted_at')->take(12),
        ]);
    }
}
