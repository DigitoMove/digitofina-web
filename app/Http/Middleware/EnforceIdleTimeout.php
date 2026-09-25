<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleTimeout
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->hasSession()) {
            return $next($request);
        }

        $now = now();
        $lastActivity = $request->session()->get('auth.idle_last_activity_at');
        $timeout = max(60, (int) config('auth.idle_timeout', 900));

        if ($lastActivity && $now->timestamp - Carbon::parse($lastActivity)->timestamp >= $timeout) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your session expired after inactivity.'], 401);
            }

            return redirect()->route('login')->with('status', 'You were logged out after a period of inactivity.');
        }

        $request->session()->put('auth.idle_last_activity_at', $now);

        return $next($request);
    }
}
