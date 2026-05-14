<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record(string $action, ?Model $auditable = null, array $newValues = [], array $oldValues = [], ?User $actor = null): void
    {
        $user = $actor ?: Auth::user();

        AuditLog::create([
            'organization_id' => $user?->organization_id,
            'branch_id' => $this->branchId($user, $auditable),
            'user_id' => $user?->id,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'action' => $action,
            'endpoint' => request()?->path(),
            'request_method' => request()?->method(),
            'response_status' => null,
            'module' => str($action)->before('.')->toString(),
            'record_type' => $auditable?->getMorphClass(),
            'record_id' => $auditable?->getKey(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'device_name' => $this->deviceName(request()),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $oldValues
     */
    public function recordApiMutation(
        Request $request,
        string $action,
        ?Model $auditable = null,
        array $newValues = [],
        array $oldValues = [],
        ?int $responseStatus = null,
        ?string $recordType = null,
        int|string|null $recordId = null,
    ): void {
        $user = $request->user();

        AuditLog::create([
            'organization_id' => $this->organizationId($user, $auditable),
            'branch_id' => $this->branchId($user, $auditable),
            'user_id' => $user?->id,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'action' => $action,
            'endpoint' => $request->path(),
            'request_method' => $request->method(),
            'response_status' => $responseStatus,
            'module' => str($action)->after('api.')->before('.')->toString() ?: 'api',
            'record_type' => $auditable?->getMorphClass() ?: $recordType,
            'record_id' => $auditable?->getKey() ?: $recordId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_name' => $this->deviceName($request),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
        ]);
    }

    private function organizationId(?User $user, ?Model $auditable): ?int
    {
        return $auditable?->organization_id ?? $user?->organization_id;
    }

    private function branchId(?User $user, ?Model $auditable): ?int
    {
        return $auditable?->branch_id ?? $user?->branch_id;
    }

    private function deviceName(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        return $request->header('X-Device-Name')
            ?: $request->input('device_name')
            ?: $request->user()?->currentAccessToken()?->name;
    }
}
