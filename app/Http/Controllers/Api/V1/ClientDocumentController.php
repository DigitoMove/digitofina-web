<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientDocumentRequest;
use App\Http\Resources\ClientDocumentResource;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Services\AuditLogger;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ClientDocumentController extends Controller
{
    public function index(Client $client): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $client);
        Gate::authorize('view', $client);

        $documents = ApiQuery::apply(
            $client->documents()->getQuery()->with(['uploadedBy', 'verifiedBy']),
            request(),
            ['type', 'title', 'content_hash'],
            ['type', 'verification_status'],
            ['id', 'type', 'verification_status', 'created_at', 'updated_at'],
        )
            ->paginate(ApiQuery::perPage(request(), 20));

        return ApiResponse::collection(
            ClientDocumentResource::collection($documents),
            'Client documents retrieved.',
            meta: ApiQuery::meta(request())
        );
    }

    public function store(StoreClientDocumentRequest $request, Client $client, AuditLogger $auditLogger): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $client);
        Gate::authorize('uploadDocument', $client);

        $file = $request->file('document');
        $path = $file->store("clients/{$client->id}/documents", 'local');

        $document = ClientDocument::create([
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'uploaded_by' => $request->user()->id,
            'type' => $request->validated('type'),
            'title' => $request->validated('title'),
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'content_hash' => $request->validated('content_hash') ?? hash_file('sha256', $file->getRealPath()),
            'image_width' => $request->validated('image_width'),
            'image_height' => $request->validated('image_height'),
            'compression_metadata' => $request->validated('compression_metadata', []),
            'local_uuid' => $request->validated('local_uuid'),
            'captured_at' => $request->validated('captured_at'),
            'verification_status' => 'pending',
        ]);

        $auditLogger->record('client.document_uploaded', $document, $document->toArray());

        return ApiResponse::resource(new ClientDocumentResource($document->load(['uploadedBy', 'verifiedBy'])), 'Client document uploaded successfully.', 201);
    }

    public function download(Client $client, ClientDocument $document): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $client);
        Gate::authorize('view', $client);
        abort_unless($document->client_id === $client->id, 404);

        return ApiResponse::resource(new ClientDocumentResource($document->load(['uploadedBy', 'verifiedBy'])), 'Client document retrieved.');
    }
}
