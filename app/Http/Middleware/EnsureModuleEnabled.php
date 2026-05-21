<?php

namespace App\Http\Middleware;

use App\Services\ModuleService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function __construct(private ModuleService $modules) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module = 'auto'): Response
    {
        $user = $request->user();
        $module = $module === 'auto' ? $this->inferModule($request) : $module;

        if (! $module || ! $user || $user->hasRole('super-admin')) {
            return $next($request);
        }

        if (! $this->modules->isEnabled($user->organization, $module)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('This module is disabled for your organization.', ['module' => $module], 403);
            }

            abort(403, 'This module is disabled for your organization.');
        }

        return $next($request);
    }

    private function inferModule(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        $path = str_starts_with($path, 'api/v1/') ? substr($path, 7) : $path;
        $segment = strtok($path, '/');

        return match (true) {
            in_array($segment, ['clients', 'client-groups', 'member'], true) => 'clients',
            str_starts_with($path, 'clients/') && str_contains($path, '/documents') => 'documents',
            str_starts_with($path, 'clients/') && str_contains($path, '/loans') => 'loans',
            str_starts_with($path, 'clients/') && str_contains($path, '/savings-accounts') => 'savings',
            in_array($segment, ['users', 'branches', 'organization'], true) => 'users',
            $segment === 'roles' => 'roles',
            $segment === 'settings' => 'settings',
            $segment === 'reports' => 'reports',
            $segment === 'audit-logs' => 'audit-logs',
            in_array($segment, ['savings', 'savings-products', 'savings-accounts', 'savings-transfers', 'savings-transactions', 'savings-goals'], true) => 'savings',
            in_array($segment, ['fixed-savings'], true) => 'fixed-deposits',
            in_array($segment, ['group-savings'], true) => 'group-savings',
            in_array($segment, ['loans', 'loan-products', 'loan-repayments', 'arrears', 'due-repayments'], true) => 'loans',
            in_array($segment, ['investment-plans', 'investor-accounts', 'investment-accounts', 'investment-deposits'], true) => 'investments',
            $segment === 'financial-relationships' => 'investments',
            in_array($segment, ['teller-drawer', 'non-cash-transactions', 'end-of-day'], true) => 'teller',
            $segment === 'accounting' => 'accounting',
            $segment === 'payments' => 'mobile-money',
            $segment === 'notifications' => 'sms',
            $segment === 'sync' => 'api-access',
            default => null,
        };
    }
}
