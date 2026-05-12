<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientNoteRequest;
use App\Models\Client;
use App\Models\ClientNote;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientNoteController extends Controller
{
    public function store(StoreClientNoteRequest $request, Client $client, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $client);

        $note = ClientNote::create($request->validated() + [
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
        ]);

        $auditLogger->record('client.note_added', $note, $note->toArray());

        return redirect()->route('clients.show', $client)->with('status', 'Client note added.');
    }
}
