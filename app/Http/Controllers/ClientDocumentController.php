<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientDocumentRequest;
use App\Http\Requests\VerifyClientDocumentRequest;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientDocumentController extends Controller
{
    public function store(StoreClientDocumentRequest $request, Client $client, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('uploadDocument', $client);

        $file = $request->file('document');
        $path = $file->store("clients/{$client->id}/documents", 'local');

        $document = ClientDocument::create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'uploaded_by' => $request->user()->id,
            'type' => $request->validated('type'),
            'title' => $request->validated('title'),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'verification_status' => 'pending',
        ]);

        if ($document->type === 'profile_photo') {
            $client->update(['photo_path' => $path]);
        }

        $auditLogger->record('client.document_uploaded', $document, $document->toArray());

        return redirect()->route('clients.show', $client)->with('status', 'Client document uploaded.');
    }

    public function download(Client $client, ClientDocument $document): StreamedResponse
    {
        Gate::authorize('view', $client);
        abort_unless($document->client_id === $client->id, 404);

        return Storage::disk($document->disk)->download($document->path, str($document->title)->slug().'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }

    public function verify(VerifyClientDocumentRequest $request, Client $client, ClientDocument $document, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('verifyKyc', $client);
        abort_unless($document->client_id === $client->id, 404);

        $old = $document->toArray();
        $document->update([
            'verification_status' => $request->validated('verification_status'),
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        $auditLogger->record('client.document_verified', $document, $document->fresh()->toArray(), $old);

        return redirect()->route('clients.show', $client)->with('status', 'Document status updated.');
    }
}
