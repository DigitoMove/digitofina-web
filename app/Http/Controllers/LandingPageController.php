<?php

namespace App\Http\Controllers;

use App\Services\DemoAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function __invoke(Request $request, DemoAccessService $demoAccess): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        if (! $demoAccess->isAvailableFor($request)) {
            return redirect()->route('login');
        }

        return view('landing');
    }
}
