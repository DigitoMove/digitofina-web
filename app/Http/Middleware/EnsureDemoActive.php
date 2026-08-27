<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $user?->organization;

        if (! $user || $user->hasRole('super-admin') || ! $organization?->isDemoExpired()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            $accessToken = $user->currentAccessToken();

            if ($accessToken && method_exists($accessToken, 'delete')) {
                $accessToken->delete();
            }

            return ApiResponse::error(
                'This demo expired after 14 days. Contact DigitoFina to continue with a full account.',
                status: 403,
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('demo.expired');

    }
}
