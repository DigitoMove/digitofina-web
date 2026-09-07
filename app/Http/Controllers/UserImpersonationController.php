<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class UserImpersonationController extends Controller
{
    public const IMPERSONATOR_SESSION_KEY = ImpersonationService::IMPERSONATOR_SESSION_KEY;

    public function index(Request $request, ImpersonationService $impersonation): View
    {
        $impersonation->switchingAdministrator($request);
        $request->validate(['search' => ['nullable', 'string', 'max:191']]);
        $search = $request->string('search')->trim()->toString();
        $users = User::query()
            ->with(['organization', 'role', 'branch' => function (BelongsTo $query): void {
                $query->withoutGlobalScope('tenant');
            }])
            ->where('is_active', true)
            ->where('status', 'active')
            ->whereKeyNot($request->user()->id)
            ->whereHas('role', fn (Builder $query): Builder => $query->whereNotIn('slug', [RoleCatalog::SUPER_ADMIN_SLUG, RoleCatalog::MEMBER_SLUG]))
            ->whereHas('organization', function (Builder $query): void {
                $query->where('status', 'active')
                    ->where(fn (Builder $query): Builder => $query->where('is_demo', false)->orWhereNull('demo_expires_at')->orWhere('demo_expires_at', '>', now()));
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(fn (Builder $query): Builder => $query->whereAny(['name', 'email'], 'like', "%{$search}%")
                    ->orWhereHas('organization', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('users.impersonate', compact('users', 'search'));
    }

    public function store(Request $request, User $user, ImpersonationService $impersonation): RedirectResponse
    {
        try {
            if ($impersonation->isActive($request)) {
                $impersonation->switchUser($request, $user);
            } else {
                $impersonation->startUser($request, $user);
            }
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }

            return back()->withErrors(['impersonation' => $exception->getMessage()]);
        }

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
