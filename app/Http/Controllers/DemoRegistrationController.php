<?php

namespace App\Http\Controllers;

use App\Actions\CreateDemoOrganization;
use App\Http\Requests\StoreDemoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DemoRegistrationController extends Controller
{
    public function create(): View
    {
        return view('demo.create', [
            'trialDays' => (int) config('demo.trial_days', 14),
            'limits' => config('demo.limits', []),
        ]);
    }

    public function store(StoreDemoRequest $request, CreateDemoOrganization $createDemo): RedirectResponse
    {
        $demo = $createDemo->handle($request->validated());
        Auth::login($demo['user']);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Your 14-day DigitoFina demo is ready. Explore every module with your demo limits.');
    }

    public function expired(): View
    {
        return view('demo.expired');
    }
}
