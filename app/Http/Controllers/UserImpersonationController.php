<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserImpersonationController extends Controller
{
    public const IMPERSONATOR_SESSION_KEY = 'impersonator_user_id';

    private const PREVIOUS_ORGANIZATION_SESSION_KEY = 'impersonator_previous_organization_id';

    public function store(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $impersonator = $request->user();

        abort_unless($impersonator->hasRole(RoleCatalog::SUPER_ADMIN_SLUG), 403);
        abort_if($request->session()->has(self::IMPERSONATOR_SESSION_KEY), 409, 'Stop the current user session before acting as another user.');
        abort_if($user->hasRole(RoleCatalog::SUPER_ADMIN_SLUG), 422, 'You cannot act as another Super Admin.');
        abort_unless($user->is_active && $user->status === 'active', 422, 'You can only act as an active user.');
        abort_unless($user->organization && $user->organization->status === 'active', 422, 'The selected user must belong to an active organization.');
        abort_if($user->organization->isDemoExpired(), 422, 'The selected user belongs to an expired demo organization.');

        $previousOrganizationId = $request->session()->get('super_admin_organization_id');
        $auditLogger->record('auth.impersonation.started', $user, [
            'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $user->id,
        ], actor: $impersonator);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::IMPERSONATOR_SESSION_KEY, $impersonator->id);
        $request->session()->put(self::PREVIOUS_ORGANIZATION_SESSION_KEY, $previousOrganizationId);
        $request->session()->forget('super_admin_organization_id');

        return redirect()->route('dashboard')->with('status', "You are now acting as {$user->name} ({$user->role?->name}).");
    }

    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $impersonatorId = $request->session()->get(self::IMPERSONATOR_SESSION_KEY);
        abort_unless($impersonatorId, 403, 'No impersonated user session is active.');

        $impersonator = User::query()->with('role')->findOrFail($impersonatorId);
        abort_unless($impersonator->hasRole(RoleCatalog::SUPER_ADMIN_SLUG), 403);

        $impersonatedUser = $request->user();
        $previousOrganizationId = $request->session()->get(self::PREVIOUS_ORGANIZATION_SESSION_KEY);
        $auditLogger->record('auth.impersonation.stopped', $impersonatedUser, [
            'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $impersonatedUser->id,
        ], actor: $impersonator);

        Auth::login($impersonator);
        $request->session()->regenerate();
        $request->session()->forget([
            self::IMPERSONATOR_SESSION_KEY,
            self::PREVIOUS_ORGANIZATION_SESSION_KEY,
            'super_admin_organization_id',
        ]);

        if ($previousOrganizationId && Organization::query()->whereKey($previousOrganizationId)->where('status', 'active')->exists()) {
            $request->session()->put('super_admin_organization_id', $previousOrganizationId);
        }

        return redirect()->route('dashboard')->with('status', 'Returned to your Super Admin account.');
    }
}
