<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCommercialQuoteRequest;
use App\Http\Requests\IssueCommercialQuoteRequest;
use App\Http\Requests\StoreCommercialQuoteRequest;
use App\Models\CommercialQuote;
use App\Models\NylonPayCredential;
use App\Models\Organization;
use App\Services\BillingAdministrationService;
use App\Services\NylonPayClient;
use App\Services\QuoteBillingService;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CommercialQuoteController extends Controller
{
    public function index(Request $request, NylonPayClient $nylonPay, BillingAdministrationService $billing, TenantContext $tenant): View
    {
        Gate::authorize('viewAny', CommercialQuote::class);
        $user = $request->user();
        $billingOrganization = $user->hasRole('super-admin') ? $tenant->organization() : $user->organization;
        $hostingFee = $billingOrganization ? $billing->ensureCurrentHostingFee($billingOrganization) : null;
        $currentSubscription = $billingOrganization?->latestSubscription()->with(['quote', 'assigner'])->first();

        $quoteQuery = CommercialQuote::query()
            ->with(['organization', 'requester', 'issuer', 'subscription', 'hostingFee'])
            ->whereIn('billing_type', ['subscription', 'one_time'])
            ->when(! $user->hasRole('super-admin'), fn (Builder $query): Builder => $query->where('organization_id', $user->organization_id))
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn (Builder $query): Builder => $query->where(function (Builder $query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where('quote_number', 'like', $search)->orWhereHas('organization', fn (Builder $query): Builder => $query->where('name', 'like', $search));
            }));
        $allQuotes = (clone $quoteQuery)->get(['id', 'status', 'amount', 'created_at']);
        $monthly = collect(range(5, 0))->map(function (int $months) use ($quoteQuery): array {
            $date = now()->subMonths($months);
            $query = (clone $quoteQuery)->whereBetween('created_at', [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()]);

            return ['label' => $date->format('M'), 'total' => (float) $query->sum('amount'), 'count' => (int) $query->count()];
        });

        return view('commercial-quotes.index', [
            'quotes' => $quoteQuery->latest('id')->paginate(20)->withQueryString(),
            'quoteStats' => [
                'total' => $allQuotes->count(),
                'requested' => $allQuotes->where('status', 'requested')->count(),
                'issued' => $allQuotes->whereIn('status', ['issued', 'payment_pending'])->count(),
                'paid' => $allQuotes->where('status', 'paid')->count(),
                'value' => (float) $allQuotes->sum('amount'),
                'paidValue' => (float) $allQuotes->where('status', 'paid')->sum('amount'),
            ],
            'monthlyQuotes' => $monthly,
            'isSuperAdmin' => $user->hasRole('super-admin'),
            'nylonPayConfigured' => $nylonPay->isConfigured(),
            'nylonPayCredential' => $user->hasRole('super-admin') ? NylonPayCredential::query()->where('scope_key', 'platform:default')->first() : null,
            'webhookUrl' => route('api.v1.webhooks.nylon-pay'),
            'organizations' => $user->hasRole('super-admin') ? Organization::query()->where('status', 'active')->orderBy('name')->get() : collect(),
            'billingOrganization' => $billingOrganization,
            'currentSubscription' => $currentSubscription,
            'hostingFee' => $hostingFee,
        ]);
    }

    public function store(StoreCommercialQuoteRequest $request, QuoteBillingService $billing): RedirectResponse
    {
        $quote = $billing->request($request->user()->organization, $request->user(), $request->validated());

        return redirect()->route('commercial-quotes.index')->with('status', "Quote request {$quote->quote_number} sent to the Super Admin.");
    }

    public function issue(IssueCommercialQuoteRequest $request, CommercialQuote $commercialQuote, QuoteBillingService $billing): RedirectResponse
    {
        $billing->issue($commercialQuote, $request->user(), $request->validated());

        return redirect()->route('commercial-quotes.index')->with('status', "Quote {$commercialQuote->quote_number} issued.");
    }

    public function storeForOrganization(CreateCommercialQuoteRequest $request, QuoteBillingService $billing): RedirectResponse
    {
        $organization = Organization::query()->findOrFail($request->integer('organization_id'));
        $quote = $billing->createAndIssue($organization, $request->user(), $request->validated());

        return redirect()->route('commercial-quotes.index')->with('status', "Quote {$quote->quote_number} created and sent to {$organization->name}.");
    }

    public function markPaid(Request $request, CommercialQuote $commercialQuote, QuoteBillingService $billing): RedirectResponse
    {
        Gate::authorize('update', $commercialQuote);
        $billing->markPaid($commercialQuote, $request->user());

        return back()->with('status', "Quote {$commercialQuote->quote_number} marked paid and access activated.");
    }

    public function destroy(Request $request, CommercialQuote $commercialQuote): RedirectResponse
    {
        Gate::authorize('delete', $commercialQuote);
        abort_if($commercialQuote->subscription()->exists() || $commercialQuote->hostingFee()->exists(), 422, 'Quotes linked to billing records cannot be deleted.');
        $number = $commercialQuote->quote_number;
        $commercialQuote->delete();

        return back()->with('status', "Quote {$number} deleted.");
    }
}
