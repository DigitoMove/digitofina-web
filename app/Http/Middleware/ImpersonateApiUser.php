<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ImpersonateApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('X-Impersonate-User')) {
            return $next($request);
        }

        $administrator = $request->user();
        abort_unless($administrator->hasRole('super-admin') && $administrator->is_active && $administrator->status === 'active', 403);
        $targetId = $request->header('X-Impersonate-User');
        abort_unless(ctype_digit($targetId), 422, 'Choose a valid user.');
        $target = User::with(['role', 'organization'])->findOrFail($targetId);
        abort_if($target->hasRole('super-admin'), 422, 'Choose a staff or client account.');
        abort_unless($target->is_active && $target->status === 'active', 422, 'This user is inactive.');
        abort_unless($target->organization?->status === 'active' && ! $target->organization->isDemoExpired(), 422, 'This organization is unavailable.');
        abort_if($request->is('api/v1/auth/*') && ! $request->is('api/v1/auth/me', 'api/v1/auth/permissions'), 403, 'Return to Super Admin before managing authentication.');
        $request->attributes->set('api_impersonator_id', $administrator->id);
        $request->attributes->set('api_impersonated_user_id', $target->id);
        Auth::setUser($target);
        $request->setUserResolver(fn (): User => $target);

        if ($request->is('api/v1/auth/me')) {
            app(AuditLogger::class)->record('api.impersonation.accessed', $target);
        }

        return $next($request);
    }
}
