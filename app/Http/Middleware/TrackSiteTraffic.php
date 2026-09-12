<?php

namespace App\Http\Middleware;

use App\Models\TrafficVisit;
use App\Services\TenantContext;
use App\Services\TrafficAnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackSiteTraffic
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! config('traffic.enabled') || ! $request->isMethod('GET') || ! $request->hasSession()
            || ! $response->isSuccessful() || ! str_contains($response->headers->get('Content-Type', ''), 'text/html')
            || preg_match('/bot|crawler|spider|headless|preview/i', $request->userAgent() ?? '')
            || $request->headers->has('Purpose') || $request->headers->has('Sec-Purpose')) {
            return $response;
        }

        try {
            if (! Schema::hasTable('traffic_visits')) {
                return $response;
            }
            $request->session()->put('traffic_visitor', $request->session()->get('traffic_visitor') ?: (string) Str::uuid());
            $hash = hash('sha256', $request->session()->get('traffic_visitor'));
            $host = strtolower($request->getHost());
            $organization = app(TenantContext::class)->organization() ?: $request->user()?->organization;
            $audience = $request->user() ? ($request->user()->isMember() ? 'client' : 'staff') : 'guest';
            $isDemo = (bool) $organization?->is_demo || $request->routeIs('demo.*');
            $visit = TrafficVisit::where('visitor_hash', $hash)->where('host', $host)
                ->where('organization_id', $organization?->id)->where('audience', $audience)
                ->where('is_demo', $isDemo)
                ->where('last_seen_at', '>=', now()->subMinutes(30))->latest('id')->first();
            if ($visit) {
                $visit->increment('page_views');
                $visit->update(['last_seen_at' => now()]);
            } else {
                [$domain, $subdomain] = app(TrafficAnalyticsService::class)->domainParts($host);
                $visit = TrafficVisit::create([
                    'visitor_hash' => $hash, 'host' => $host, 'domain' => $domain, 'subdomain' => $subdomain,
                    'organization_id' => $organization?->id,
                    'is_demo' => $isDemo,
                    'audience' => $audience,
                    'device' => preg_match('/Mobile|Android|iPhone|iPad/i', $request->userAgent() ?? '') ? 'mobile' : 'desktop',
                    'started_at' => now(), 'last_seen_at' => now(),
                ]);
            }
            $meta = '<meta name="df-traffic" content="'.$visit->id.'" data-endpoint="'.e(route('traffic.heartbeat', [], false)).'" data-csrf="'.e(csrf_token()).'">';
            $response->setContent(str_replace('</head>', $meta.'</head>', $response->getContent()));
            $response->headers->set('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
