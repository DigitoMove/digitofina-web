<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record(string $action, ?Model $auditable = null, array $newValues = [], array $oldValues = []): void
    {
        AuditLog::create([
            'organization_id' => Auth::user()?->organization_id,
            'user_id' => Auth::id(),
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'action' => $action,
            'module' => str($action)->before('.')->toString(),
            'record_type' => $auditable?->getMorphClass(),
            'record_id' => $auditable?->getKey(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
        ]);
    }
}
