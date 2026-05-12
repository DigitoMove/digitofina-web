<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $issued = $tokens->issue($data['login'], $data['password'], $data['device_name'] ?? 'api-client');

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $issued['token'],
            'user' => [
                'id' => $issued['user']->id,
                'name' => $issued['user']->name,
                'email' => $issued['user']->email,
                'phone' => $issued['user']->phone,
                'role' => $issued['user']->role?->slug,
                'branch_id' => $issued['user']->branch_id,
                'organization_id' => $issued['user']->organization_id,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role.permissions', 'branch', 'organization', 'client']);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role?->slug,
            'permissions' => $user->role?->permissions->pluck('slug')->values() ?? [],
            'branch' => $user->branch,
            'organization' => $user->organization,
            'client' => $user->client ? new ClientResource($user->client) : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
