<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientImpersonationController extends Controller
{
    public function store(Request $request, Client $client, ImpersonationService $impersonation): RedirectResponse
    {
        Gate::authorize('impersonate', $client);
        $impersonation->startClient($request, $client);

        return redirect()->route('member.dashboard')->with('status', "Support session started for {$client->full_name}.");
    }
}
