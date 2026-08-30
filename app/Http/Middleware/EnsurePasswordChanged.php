<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->session()->has(ImpersonationService::IMPERSONATOR_SESSION_KEY)) {
            return redirect()->route('profile.edit')->with('warning', 'Change your temporary password before using the client portal.');
        }

        return $next($request);
    }
}
