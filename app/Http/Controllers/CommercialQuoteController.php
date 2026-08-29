<?php

namespace App\Http\Controllers;

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

        return view('commercial-quotes.index', [
            'quotes' => CommercialQuote::query()
                ->with(['organization', 'requester', 'issuer', 'subscription', 'hostingFee'])
                ->when(! $user->hasRole('super-admin'), fn (Builder $query): Builder => $query->where('organization_id', $user->organization_id))
                ->latest('id')
                ->paginate(20),
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
}
