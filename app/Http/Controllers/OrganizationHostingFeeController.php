<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkOrganizationHostingFeePaidRequest;
use App\Models\OrganizationHostingFee;
use App\Services\BillingAdministrationService;
use Illuminate\Http\RedirectResponse;

class OrganizationHostingFeeController extends Controller
{
    public function update(MarkOrganizationHostingFeePaidRequest $request, OrganizationHostingFee $organizationHostingFee, BillingAdministrationService $billing): RedirectResponse
    {
        $billing->markHostingFeePaid($organizationHostingFee, $request->validated(), $request->user());

        return back()->with('status', 'Annual hosting fee marked paid.');
    }
}
