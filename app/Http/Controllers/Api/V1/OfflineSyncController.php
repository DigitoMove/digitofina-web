<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncClientsRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\OfflineSyncBatchResource;
use App\Models\Client;
use App\Services\OfflineSyncService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OfflineSyncController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        return ApiResponse::data([
            'resources' => [
                'clients' => [
                    'pull_url' => route('api.v1.sync.clients.pull', absolute: false),
                    'push_url' => route('api.v1.sync.clients.push', absolute: false),
                    'supports' => ['create', 'update', 'conflict_detection', 'local_temp_id_mapping'],
                    'max_push_records' => 100,
                    'conflict_strategy' => 'server_newer_than_mobile_snapshot',
                ],
            ],
            'server_time' => now()->toIso8601String(),
        ], 'Offline sync manifest retrieved.');
    }

    public function pullClients(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $clients = ApiQuery::apply(
            ApiSecurity::scopeTenant(Client::query(), $request->user())->with(['branch', 'officer']),
            $request,
            ['client_number', 'first_name', 'last_name', 'phone', 'national_id'],
            ['branch_id', 'status', 'client_type', 'kyc_status', 'risk_rating', 'officer_id'],
            ['id', 'client_number', 'first_name', 'last_name', 'status', 'registered_at', 'created_at', 'updated_at'],
        )->paginate(ApiQuery::perPage($request, 50, 200));

        return ApiResponse::collection(
            ClientResource::collection($clients),
            'Client sync feed retrieved.',
            meta: ApiQuery::meta($request) + [
                'sync_contract' => [
                    'local_temp_id_field' => 'sync.local_temp_id',
                    'server_reference_field' => 'client_number',
                    'conflict_timestamp_field' => 'updated_at',
                ],
            ],
        );
    }

    public function pushClients(SyncClientsRequest $request, OfflineSyncService $sync): JsonResponse
    {
        $batch = $sync->syncClients($request->user(), $request->validated());

        return ApiResponse::resource(
            new OfflineSyncBatchResource($batch->load('records')),
            'Client offline sync processed.',
            $batch->conflict_records > 0 || $batch->failed_records > 0 ? 207 : 200,
            [
                'sync' => [
                    'server_time' => now()->toIso8601String(),
                    'next_pull_updated_since' => now()->toIso8601String(),
                ],
            ],
        );
    }
}
