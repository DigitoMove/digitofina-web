<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientLoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientLoginController extends Controller
{
    public function create(): View
    {
        return view('auth.client-login');
    }

    public function store(ClientLoginRequest $request, TenantContext $tenant, AuditLogger $auditLogger): RedirectResponse
    {
        $organizationId = $tenant->id();
        $matches = User::query()
            ->with(['client', 'role', 'organization'])
            ->whereHas('role', fn ($query) => $query->where('slug', 'member'))
            ->whereHas('client', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($request): void {
                $login = $request->validated('phone');
                $query
                    ->whereIn('phone', PhoneNumber::variants($login))
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('client_number', $login));
            })
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->get()
            ->filter(fn (User $user): bool => Hash::check($request->validated('password'), $user->password));

        if ($matches->count() !== 1) {
            $auditLogger->record('auth.failed_client_login', null, ['phone' => PhoneNumber::normalize($request->validated('phone'))]);

            throw ValidationException::withMessages([
                'phone' => $organizationId
                    ? 'The phone number, client number, or password is incorrect.'
                    : 'Use your organization login address, or contact your loan office for help.',
            ]);
        }

        $user = $matches->first();
        abort_unless($user && $user->is_active && $user->status === 'active' && $user->organization?->status === 'active', 403);
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $auditLogger->record('auth.client_login', $user);

        return redirect()->intended($user->must_change_password ? route('profile.edit') : route('member.dashboard'));
    }
}
