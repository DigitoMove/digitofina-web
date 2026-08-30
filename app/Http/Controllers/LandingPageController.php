<?php

namespace App\Http\Controllers;

use App\Services\DemoAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function __invoke(Request $request, DemoAccessService $demoAccess): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        $clientPortalDomains = collect(config('client_portal.domains', []))
            ->map(fn (string $domain): string => strtolower($domain));

        if ($clientPortalDomains->contains(
            fn (string $pattern): bool => Str::is($pattern, strtolower($request->getHost()))
        )) {
            return redirect()->route('client.login');
        }

        if (! $demoAccess->isAvailableFor($request)) {
            return redirect()->route('login');
        }

        return view('landing');
    }
}
