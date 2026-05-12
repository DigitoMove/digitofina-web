<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('view-audit-logs') || $user->canPerform('audit-system');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->viewAny($user) && ($auditLog->organization_id === null || $user->organization_id === null || $user->organization_id === $auditLog->organization_id);
    }
}
