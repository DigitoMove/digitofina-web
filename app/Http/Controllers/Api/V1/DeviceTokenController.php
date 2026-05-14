<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceTokenRequest;
use App\Http\Resources\DeviceTokenResource;
use App\Models\DeviceToken;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = ApiQuery::apply(
            $request->user()->deviceTokens()->getQuery(),
            $request,
            ['device_id', 'device_name', 'device_model'],
            ['platform', 'provider'],
            ['id', 'platform', 'provider', 'last_seen_at', 'created_at', 'updated_at'],
            'last_seen_at',
        )
            ->whereNull('revoked_at')
            ->paginate(ApiQuery::perPage($request, 20));

        return ApiResponse::collection(DeviceTokenResource::collection($tokens), 'Device tokens retrieved.', meta: ApiQuery::meta($request));
    }

    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $provider = $data['provider'] ?? 'fcm';

        $token = DeviceToken::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'device_id' => $data['device_id'],
                'provider' => $provider,
            ],
            [
                'organization_id' => $request->user()->organization_id,
                'platform' => $data['platform'],
                'push_token' => $data['push_token'],
                'device_name' => $data['device_name'] ?? null,
                'device_model' => $data['device_model'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'locale' => $data['locale'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'capabilities' => $data['capabilities'] ?? [],
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        return ApiResponse::resource(new DeviceTokenResource($token), 'Device token registered successfully.', 201);
    }

    public function destroy(Request $request, DeviceToken $deviceToken): JsonResponse
    {
        abort_unless($deviceToken->user_id === $request->user()->id, 404);

        $deviceToken->update(['revoked_at' => now()]);

        return ApiResponse::resource(new DeviceTokenResource($deviceToken->fresh()), 'Device token revoked successfully.');
    }
}
