<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ApiTokenService
{
    public function __construct(private AuditLogger $auditLogger, private TenantContext $tenant) {}

    /**
     * @return array{user:User,token:string}
     */
    public function issue(string $login, string $password, string $deviceName = 'api-client', ?Request $request = null): array
    {
        $user = User::query()
            ->with('role.permissions')
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['login' => 'The provided credentials are invalid.']);
        }

        if ($user->status !== 'active' || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This user account is not active.']);
        }

        if ($this->tenant->organization() && (int) $user->organization_id !== (int) $this->tenant->id()) {
            throw ValidationException::withMessages(['login' => 'This account cannot access this organization tenant.']);
        }

        if ($user->organization?->status === 'suspended' || $user->organization?->status === 'inactive') {
            throw ValidationException::withMessages(['login' => 'This organization account is not active.']);
        }

        if ($user->organization?->isDemoExpired()) {
            throw ValidationException::withMessages(['login' => 'This 14-day demo has expired. Contact DigitoFina to continue with a full account.']);
        }

        $abilities = $user->role?->permissions->pluck('slug')->values()->all() ?: ['view-own-accounts'];
        $newToken = $user->createToken($deviceName, $abilities, now()->addDays(30));

        $deviceId = $request?->header('X-Device-Id') ?: $request?->server('HTTP_X_DEVICE_ID') ?: $deviceName;

        DB::table('personal_access_tokens')
            ->where('id', $newToken->accessToken->getKey())
            ->update([
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'device_id' => $deviceId,
            ]);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request?->ip(),
            'last_login_user_agent' => $request?->userAgent(),
        ])->save();

        $this->auditLogger->record('api.login', $user, [
            'device_name' => $deviceName,
            'device_id' => $deviceId,
            'ip_address' => $request?->ip(),
        ], actor: $user);

        return ['user' => $user, 'token' => $newToken->plainTextToken];
    }
}
