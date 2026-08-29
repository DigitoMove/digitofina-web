<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignOrganizationSubscriptionRequest;
use App\Services\BillingAdministrationService;
use Illuminate\Http\RedirectResponse;

class OrganizationSubscriptionController extends Controller
{
    public function store(AssignOrganizationSubscriptionRequest $request, BillingAdministrationService $billing): RedirectResponse
    {
        $subscription = $billing->assignSubscription($request->validated(), $request->user());

        return redirect()->route('commercial-quotes.index')->with('status', "{$subscription->organization->name} plan assigned successfully.");
    }
}
