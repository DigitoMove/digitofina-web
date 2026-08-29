<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientPortalAccessRequest;
use App\Models\Client;
use App\Services\ClientPortalAccessService;
use Illuminate\Http\RedirectResponse;

class ClientPortalAccessController extends Controller
{
    public function store(StoreClientPortalAccessRequest $request, Client $client, ClientPortalAccessService $access): RedirectResponse
    {
        $access->issue($client, $request->validated('password'), $request->user());

        return back()->with('status', 'Client portal password issued. Give it to the client securely; they must change it at first login.');
    }
}
