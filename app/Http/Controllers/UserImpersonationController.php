<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use App\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserImpersonationController extends Controller
{
    public const IMPERSONATOR_SESSION_KEY = ImpersonationService::IMPERSONATOR_SESSION_KEY;

    public function store(Request $request, User $user, ImpersonationService $impersonation): RedirectResponse
    {
        abort_unless($request->user()->hasRole(RoleCatalog::SUPER_ADMIN_SLUG), 403);
        $impersonation->startUser($request, $user);

        return redirect()->route('dashboard')->with('status', "You are now acting as {$user->name} ({$user->role?->name}).");
    }

    public function destroy(Request $request, ImpersonationService $impersonation): RedirectResponse
    {
        $result = $impersonation->stop($request);
        $impersonator = $result['impersonator'];
        $destination = $result['return_client_id']
            ? route('clients.show', $result['return_client_id'])
            : route('dashboard');

        return redirect($destination)->with('status', "Returned to your {$impersonator->role?->name} account.");
    }
}
