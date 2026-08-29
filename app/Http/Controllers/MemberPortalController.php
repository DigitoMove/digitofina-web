<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PaymentProvider;
use App\Models\PaymentRequest;
use App\Models\SavingsAccount;
use App\Services\NylonPayClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MemberPortalController extends Controller
{
    public function __invoke(NylonPayClient $nylonPay): View|RedirectResponse
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
                'loans.repayments',
                'loans.schedules',
            ])
            ->where('user_id', Auth::id())
            ->first();

        if (! $client) {
            return redirect()->route('dashboard')->withErrors(['member' => 'No member profile is linked to this login yet.']);
        }

        $savingsAccounts = $client->savingsAccounts;
        $investmentAccounts = $client->investmentAccounts;
        $loans = $client->loans;
        $legacySavingsTotal = $client->accounts->where('accountType.category', 'savings')->sum(fn ($account) => (float) $account->balance);
        $savingsTotal = $savingsAccounts->sum(fn ($account): float => (float) $account->current_balance);
        $investmentTotal = $investmentAccounts->sum(fn ($account): float => (float) $account->current_balance);
        $loanBreakdown = [
            'principal' => $loans->sum(fn (Loan $loan): float => (float) $loan->principal_balance),
            'interest' => $loans->sum(fn (Loan $loan): float => (float) $loan->interest_balance),
            'fees' => $loans->sum(fn (Loan $loan): float => (float) $loan->fees_balance),
            'penalties' => $loans->sum(fn (Loan $loan): float => (float) $loan->penalty_balance),
        ];
        $investmentGrowth = $savingsAccounts->sum(fn ($account): float => (float) $account->transactions
            ->where('type', 'interest_posting')
            ->where('status', 'successful')
            ->sum('amount')) + $investmentAccounts->sum(fn ($account): float => (float) $account->actual_return_amount);
        $recentTransactions = $savingsAccounts
            ->flatMap->transactions
            ->concat($investmentAccounts->flatMap->transactions)
            ->concat($loans->flatMap->repayments)
            ->sortByDesc(fn ($transaction): string => ($transaction->transaction_date ?? $transaction->paid_at ?? $transaction->posted_at ?? $transaction->created_at)->toDateString().' '.$transaction->created_at->format('H:i:s.u'))
            ->take(15);
        $paymentRequests = $this->paymentRequests($client, $loans, $savingsAccounts, $investmentAccounts);
        $hasOpenLoan = $loans->contains(fn (Loan $loan): bool => ! in_array($loan->status, ['fully_paid', 'closed', 'rejected', 'cancelled'], true));

        return view('member.dashboard', [
            'client' => $client,
            'savingsAccounts' => $savingsAccounts,
            'investmentAccounts' => $investmentAccounts,
            'loans' => $loans,
            'paymentProviders' => $this->paymentProviders($client),
            'paymentRequests' => $paymentRequests,
            'defaultPaymentPhone' => $client->phone ?: Auth::user()?->phone,
            'savingsTotal' => $savingsTotal ?: $legacySavingsTotal,
            'investmentTotal' => $investmentTotal,
            'investmentGrowth' => $investmentGrowth,
            'loanBreakdown' => $loanBreakdown,
            'loanBalance' => array_sum($loanBreakdown),
            'transactions' => $recentTransactions->isNotEmpty() ? $recentTransactions : $client->accounts->flatMap->transactions->sortByDesc('posted_at')->take(15),
            'canRequestLoan' => $client->status === 'active' && $client->kyc_status === 'verified' && ! $hasOpenLoan,
            'loanProducts' => LoanProduct::query()->where('organization_id', $client->organization_id)->where('status', 'active')->where('is_active', true)->orderBy('name')->get(),
            'nylonPayConfigured' => $nylonPay->isConfigured($client->organization_id),
        ]);
    }

    /**
     * @param  Collection<int, Loan>  $loans
     * @param  Collection<int, SavingsAccount>  $savingsAccounts
     * @param  Collection<int, InvestmentAccount>  $investmentAccounts
     * @return Collection<int, PaymentRequest>
     */
    private function paymentRequests(Client $client, Collection $loans, Collection $savingsAccounts, Collection $investmentAccounts): Collection
    {
        return PaymentRequest::query()
            ->with(['provider', 'payable', 'postedTransaction'])
            ->where('organization_id', $client->organization_id)
            ->where(function ($query) use ($loans, $savingsAccounts, $investmentAccounts): void {
                $query
                    ->where(function ($query) use ($loans): void {
                        $query->where('payable_type', (new Loan)->getMorphClass())->whereIn('payable_id', $loans->pluck('id'));
                    })
                    ->orWhere(function ($query) use ($savingsAccounts): void {
                        $query->where('payable_type', (new SavingsAccount)->getMorphClass())->whereIn('payable_id', $savingsAccounts->pluck('id'));
                    })
                    ->orWhere(function ($query) use ($investmentAccounts): void {
                        $query->where('payable_type', (new InvestmentAccount)->getMorphClass())->whereIn('payable_id', $investmentAccounts->pluck('id'));
                    });
            })
            ->latest()
            ->take(10)
            ->get();
    }

    /**
     * @return Collection<int, PaymentProvider>
     */
    private function paymentProviders(Client $client): Collection
    {
        return PaymentProvider::query()
            ->where('status', 'active')
            ->where(function ($query) use ($client): void {
                $query
                    ->where('organization_id', $client->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }
}
