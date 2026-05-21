<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->clear();

        $organization = $this->resolveFromHost($request->getHost());
        $user = $request->user();

        if ($user) {
            if ($user->hasRole('super-admin')) {
                $selectedOrganization = $request->session()->has('super_admin_organization_id')
                    ? Organization::query()->find($request->session()->get('super_admin_organization_id'))
                    : null;

                $this->tenant->set($selectedOrganization ?: $organization, (bool) $selectedOrganization);

                return $next($request);
            }

            abort_unless($user->organization_id, 403, 'Your account is not assigned to an organization.');
            abort_if($user->organization?->status !== 'active', 403, 'Your organization is not active.');

            if ($organization) {
                abort_unless((int) $user->organization_id === (int) $organization->id, 403, 'You cannot access another organization tenant.');
            }

            $this->tenant->set($organization ?: $user->organization);

            return $next($request);
        }

        $this->tenant->set($organization);

        return $next($request);
    }

    private function resolveFromHost(string $host): ?Organization
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?: $host);
        $centralDomains = collect(config('tenancy.central_domains', []))
            ->map(fn (string $domain): string => strtolower($domain))
            ->all();

        if (in_array($host, $centralDomains, true)) {
            return null;
        }

        $domain = OrganizationDomain::query()
            ->with('organization')
            ->where('domain', $host)
            ->where('status', 'active')
            ->first();

        if ($domain) {
            return $domain->organization;
        }

        $rootDomain = strtolower((string) config('tenancy.root_domain', 'gateoffina.com'));

        if (! str_ends_with($host, ".{$rootDomain}")) {
            return null;
        }

        $subdomain = str($host)->before(".{$rootDomain}")->toString();

        if ($subdomain === '' || in_array($subdomain, ['app', 'www'], true)) {
            return null;
        }

        return Organization::query()
            ->where('slug', $subdomain)
            ->where('status', 'active')
            ->first();
    }
}
