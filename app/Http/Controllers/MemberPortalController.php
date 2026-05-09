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
                'loans.product',
                'loans.schedules',
            ])
            ->where('user_id', Auth::id())
            ->first();

        if (! $client) {
            return redirect()->route('dashboard')->withErrors(['member' => 'No member profile is linked to this login yet.']);
        }

        return view('member.dashboard', [
            'client' => $client,
            'savingsTotal' => $client->accounts->where('accountType.category', 'savings')->sum(fn ($account) => (float) $account->balance),
            'loanBalance' => $client->loans->sum(fn ($loan) => (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance),
            'transactions' => $client->accounts->flatMap->transactions->sortByDesc('posted_at')->take(12),
        ]);
    }
}
