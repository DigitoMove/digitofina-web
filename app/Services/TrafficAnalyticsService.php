<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\TrafficVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TrafficAnalyticsService
{
    /** @return array{0: string, 1: ?string} */
    public function domainParts(string $host): array
    {
        $roots = collect(config('traffic.root_domains', []))
            ->push(config('tenancy.root_domain'))
            ->merge(config('tenancy.central_domains', []))
            ->filter()->map(fn (string $root): string => strtolower(trim($root, '.')))->unique()->sortBy(fn (string $root): int => strlen($root));
        foreach ($roots as $root) {
            if ($host === $root || str_ends_with($host, '.'.$root)) {
                return [$root, $host === $root ? null : substr($host, 0, -strlen($root) - 1)];
            }
        }

        return [$host, null];
    }

    /** @return array<string, mixed> */
    public function dashboard(Request $request, ?int $organizationId = null): array
    {
        $filters = $request->validate([
            'traffic_days' => ['nullable', 'integer', 'in:7,30,90'],
            'traffic_host' => ['nullable', 'string', 'max:253'],
            'traffic_kind' => ['nullable', 'in:all,live,demo'],
            'traffic_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $days = (int) ($filters['traffic_days'] ?? 30);
        $host = $filters['traffic_host'] ?? '';
        $kind = $filters['traffic_kind'] ?? 'all';
        $start = today()->subDays($days - 1);
        $ready = Schema::hasTable('traffic_visits');
        $scope = fn (): Builder => TrafficVisit::query()
            ->when($organizationId, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->when($host !== '', fn (Builder $q): Builder => $q->where('host', $host))
            ->when($kind !== 'all', fn (Builder $q): Builder => $q->where('is_demo', $kind === 'demo'));
        $query = $scope()->where('started_at', '>=', $start)->where('started_at', '<=', now());
        $totals = $ready ? (clone $query)->selectRaw('COUNT(*) as visits, COUNT(DISTINCT visitor_hash) as visitors, COALESCE(SUM(page_views), 0) as views, COALESCE(AVG(active_seconds), 0) as duration, COUNT(DISTINCT host) as hosts, COALESCE(SUM(CASE WHEN is_demo = 1 THEN 1 ELSE 0 END), 0) as demos')->first()->toArray() : [];
        $daily = $ready ? (clone $query)->selectRaw('DATE(started_at) as day, COUNT(*) as visits, SUM(page_views) as views')->groupByRaw('DATE(started_at)')->orderBy('day')->get()->keyBy('day') : collect();
        $trend = collect(range(0, $days - 1))->map(function (int $offset) use ($start, $daily): array {
            $date = $start->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'visits' => (int) ($daily[$date]->visits ?? 0), 'views' => (int) ($daily[$date]->views ?? 0)];
        });
        $hosts = $ready ? (clone $query)->select(['host', 'domain', 'subdomain', 'organization_id', 'is_demo'])
            ->selectRaw('COUNT(*) as visits, SUM(page_views) as views, AVG(active_seconds) as duration, MAX(last_seen_at) as last_seen')
            ->groupBy('host', 'domain', 'subdomain', 'organization_id', 'is_demo')->orderByDesc('visits')->orderBy('host')
            ->paginate(15, ['*'], 'traffic_page')->withQueryString()->fragment('traffic-domains') : null;
        $breakdown = $ready ? (clone $query)->select('audience')->selectRaw('COUNT(*) as visits')->groupBy('audience')->pluck('visits', 'audience') : collect();
        $previous = $ready ? $scope()->whereBetween('started_at', [$start->copy()->subDays($days), $start->copy()->subSecond()])->count() : 0;
        $knownHosts = OrganizationDomain::query()->where('status', 'active')
            ->when($organizationId, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))->pluck('domain');
        if (! $organizationId) {
            $knownHosts = $knownHosts->merge(config('tenancy.central_domains', []));
        }
        if ($ready) {
            $knownHosts = $knownHosts->merge(TrafficVisit::query()->when($organizationId, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))->distinct()->pluck('host'));
        }

        return compact('ready', 'days', 'host', 'kind', 'totals', 'trend', 'hosts', 'breakdown', 'previous') + [
            'known_hosts' => $knownHosts->unique()->sort()->values(),
            'online' => $ready ? $scope()->where('last_seen_at', '>=', now()->subMinutes(5))->distinct()->count('visitor_hash') : 0,
            'organizations' => Organization::query()->pluck('name', 'id'),
        ];
    }
}
