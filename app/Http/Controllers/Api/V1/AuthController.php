<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\ClientResource;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

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

    public function permissions(Request $request): JsonResponse
    {
        $permissions = $request->user()->role?->permissions()->orderBy('slug')->pluck('slug') ?? collect();

        return response()->json(['permissions' => $permissions->values()]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()?->delete();
        $issued = $user->createToken($request->string('device_name')->toString() ?: 'api-client', ['*'], now()->addDays(30));

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $issued->plainTextToken,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($data);

        return response()->json([
            'status' => $status === Password::RESET_LINK_SENT ? 'sent' : 'failed',
            'message' => __($status),
        ], $status === Password::RESET_LINK_SENT ? 200 : 422);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update(['password' => Hash::make($request->validated('password'))]);

        return response()->json(['message' => 'Password changed.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
