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

        if (! $user || $user->hasRole('super-admin')) {
            return $next($request);
        }

        if ($organization?->hasExpiredSubscription()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Your DigitoFina subscription has expired. Request a renewal quote to continue.', status: 403);
            }

            return redirect()->route('commercial-quotes.index')->with('warning', 'Your DigitoFina subscription has expired. Request a renewal quote to continue.');
        }

        if (! $organization?->isDemoExpired()) {
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
