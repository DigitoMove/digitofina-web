<?php

namespace App\Http\Middleware;

use App\Services\DemoAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoAvailable
{
    public function __construct(private DemoAccessService $demoAccess) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->demoAccess->isAvailableFor($request)) {
            return redirect()
                ->route('login')
                ->with('warning', 'Public marketing, pricing and demo registration are not available on this organization domain. Please sign in with your existing account.');
        }

        return $next($request);
    }
}
