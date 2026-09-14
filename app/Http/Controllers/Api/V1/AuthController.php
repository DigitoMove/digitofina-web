<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\ClientResource;
use App\Services\ApiTokenService;
use App\Services\AuditLogger;
use App\Services\ModuleService;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $issued = $tokens->issue($data['login'], $data['password'], $data['device_name'] ?? 'api-client', $request);

        return ApiResponse::data([
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
        ], 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role.permissions', 'branch', 'organization', 'client']);
        $organization = app(TenantContext::class)->organization();

        return ApiResponse::data([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role?->slug,
            'context' => [
                'organization_id' => $organization?->id,
                'organization_name' => $organization?->name,
                'currency' => $organization?->currency,
                'branch_id' => $user->branch_id,
                'can_choose_company' => $user->hasRole('super-admin'),
                'can_choose_branch' => ApiSecurity::hasGlobalBranchAccess($user),
                'impersonator_id' => $request->attributes->get('api_impersonator_id'),
                'enabled_modules' => $organization ? app(ModuleService::class)->enabledSlugs($organization) : [],
                'is_global' => $organization === null && $user->hasRole('super-admin'),
            ],
            'permissions' => $user->role?->permissions->pluck('slug')->values() ?? [],
            'effective_permissions' => $user->role?->permissions->pluck('slug')->filter(fn (string $permission): bool => $user->canPerform($permission))->values() ?? [],
            'capabilities' => [
                'create_clients' => ! $user->isMember() && ! $user->isTellerCashier() && $user->canPerform('manage-clients'),
                'create_loans' => $user->canCreateLoanApplications(),
                'view_reports' => ! $user->isMember() && ! $user->isLoanOfficer() && ! $user->isLoanRecoveryOfficer() && $user->canPerform('view-reports'),
                'post_transactions' => $user->canPerform('post-transactions'),
            ],
            'branch' => $user->branch ? [
                'id' => $user->branch->id,
                'name' => $user->branch->name,
                'code' => $user->branch->code,
            ] : null,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
            ] : null,
            'client' => $user->client ? (new ClientResource($user->client))->resolve($request) : null,
        ], 'User profile retrieved.');
    }

    public function permissions(Request $request): JsonResponse
    {
        $permissions = $request->user()->role?->permissions()->orderBy('slug')->pluck('slug') ?? collect();

        return ApiResponse::data(['permissions' => $permissions->values()], 'User permissions retrieved.');
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()?->delete();
        $abilities = $user->role?->permissions()->pluck('slug')->all() ?: ['view-own-accounts'];
        $issued = $user->createToken($request->string('device_name')->toString() ?: 'api-client', $abilities, now()->addDays(30));

        DB::table('personal_access_tokens')
            ->where('id', $issued->accessToken->getKey())
            ->update([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_id' => $request->header('X-Device-Id') ?: $request->server('HTTP_X_DEVICE_ID') ?: ($request->string('device_name')->toString() ?: 'api-client'),
                'last_refreshed_at' => now(),
            ]);

        return ApiResponse::data([
            'token_type' => 'Bearer',
            'access_token' => $issued->plainTextToken,
        ], 'Token refreshed.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($data);

        if ($status !== Password::RESET_LINK_SENT) {
            return ApiResponse::error(__($status), ['email' => [__($status)]], 422);
        }

        return ApiResponse::data(['status' => 'sent'], __($status));
    }

    public function changePassword(ChangePasswordRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $request->user()->update(['password' => Hash::make($request->validated('password'))]);
        $auditLogger->record('api.password_changed', $request->user(), [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::data(message: 'Password changed.');
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $auditLogger->record('api.logout', $request->user(), [
            'token_id' => $request->user()->currentAccessToken()?->getKey(),
            'ip_address' => $request->ip(),
        ]);
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::data(message: 'Logged out.');
    }
}
