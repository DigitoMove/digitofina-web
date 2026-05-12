<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientDocumentRequest;
use App\Http\Resources\ClientDocumentResource;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Services\AuditLogger;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientDocumentController extends Controller
{
    public function index(Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        return ClientDocumentResource::collection($client->documents()->with(['uploadedBy', 'verifiedBy'])->latest()->paginate(request()->integer('per_page', 20)));
    }

    public function store(StoreClientDocumentRequest $request, Client $client, AuditLogger $auditLogger): ClientDocumentResource
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

        $auditLogger->record('client.document_uploaded', $document, $document->toArray());

        return new ClientDocumentResource($document->load(['uploadedBy', 'verifiedBy']));
    }

    public function download(Client $client, ClientDocument $document): StreamedResponse
    {
        Gate::authorize('view', $client);
        abort_unless($document->client_id === $client->id, 404);

        return Storage::disk($document->disk)->download($document->path, str($document->title)->slug().'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }
}
