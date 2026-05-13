<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientDocumentRequest;
use App\Http\Resources\ClientDocumentResource;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ClientDocumentController extends Controller
{
    public function index(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return ApiResponse::collection(
            ClientDocumentResource::collection($client->documents()->with(['uploadedBy', 'verifiedBy'])->latest()->paginate(request()->integer('per_page', 20))),
            'Client documents retrieved.'
        );
    }

    public function store(StoreClientDocumentRequest $request, Client $client, AuditLogger $auditLogger): JsonResponse
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

        return ApiResponse::resource(new ClientDocumentResource($document->load(['uploadedBy', 'verifiedBy'])), 'Client document uploaded successfully.', 201);
    }

    public function download(Client $client, ClientDocument $document): JsonResponse
    {
        Gate::authorize('view', $client);
        abort_unless($document->client_id === $client->id, 404);

        return ApiResponse::resource(new ClientDocumentResource($document->load(['uploadedBy', 'verifiedBy'])), 'Client document retrieved.');
    }
}
