<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\DemoAccessService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request, DemoAccessService $demoAccess): View
    {
        return view('auth.login', [
            'demoAvailable' => $demoAccess->isAvailableFor($request),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger, TenantContext $tenant): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $credentials = [
            $field => $data['login'],
            'password' => $data['password'],
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $auditLogger->record('auth.failed_login', null, ['login' => $data['login']]);

            throw ValidationException::withMessages([
                'login' => 'The provided credentials do not match DigitoFina records.',
            ]);
        }

        if (Auth::user()->isMember()) {
            $auditLogger->record('auth.member_staff_login_blocked', Auth::user());
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'Client accounts must sign in through the client login page.',
            ]);
        }

        if ($tenant->organization() && (int) Auth::user()->organization_id !== (int) $tenant->id()) {
            $auditLogger->record('auth.cross_tenant_login_blocked', Auth::user(), ['tenant_organization_id' => $tenant->id()]);
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'This account cannot access this organization tenant.',
            ]);
        }

        if (Auth::user()->status !== 'active' || ! Auth::user()->is_active || Auth::user()->organization?->status === 'suspended' || Auth::user()->organization?->status === 'inactive') {
            $auditLogger->record('auth.blocked_login', Auth::user());
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'This staff or organization account is not active. Contact your system administrator.',
            ]);
        }

        if (Auth::user()->organization?->isDemoExpired()) {
            $auditLogger->record('auth.expired_demo_login_blocked', Auth::user());
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'This 14-day demo has expired. Contact DigitoFina to continue with a full account.',
            ]);
        }

        $request->session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now()])->save();
        $auditLogger->record('auth.login', Auth::user());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $auditLogger->record('auth.logout', Auth::user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
