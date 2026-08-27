<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoCapacity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $organization = $request->user()?->organization;

        if (! $request->isMethod('post') || ! $organization?->is_demo || $request->user()?->hasRole('super-admin')) {
            return $next($request);
        }

        $limit = (int) config("demo.limits.{$resource}", 0);

        if ($limit <= 0 || $this->resourceCount($organization, $resource) < $limit) {
            return $next($request);
        }

        throw ValidationException::withMessages([
            'demo_limit' => "This demo is limited to {$limit} {$resource}. Your existing records are unchanged.",
        ]);

    }

    private function resourceCount(Organization $organization, string $resource): int
    {
        return match ($resource) {
            'branches' => $organization->branches()->count(),
            'users' => $organization->users()->count(),
            'clients' => $organization->clients()->count(),
            'loans' => $organization->loans()->count(),
            default => 0,
        };
    }
}
