<?php

namespace App\Http\Middleware;

use App\Models\UserActivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if (! $user || $request->is('api/*') || ! $request->hasSession()) {
            return $response;
        }

        $deviceId = $request->cookie('df_device_id') ?: (string) Str::uuid();
        $userAgent = $request->userAgent();
        $platform = $this->platform($userAgent);
        $activity = UserActivity::query()->firstOrNew([
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
        ]);
        $activity->fill([
            'organization_id' => $user->organization_id,
            'device_key' => hash('sha256', $deviceId),
            'device_name' => $this->deviceName($userAgent, $platform),
            'platform' => $platform,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'last_route' => $request->route()?->getName() ?: $request->path(),
            'last_seen_at' => now(),
        ]);
        $activity->first_seen_at ??= now();
        $activity->save();

        if (! $request->cookie('df_device_id')) {
            $response->headers->setCookie(cookie('df_device_id', $deviceId, 60 * 24 * 365 * 2, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }

    private function platform(?string $userAgent): string
    {
        return match (true) {
            blank($userAgent) => 'Unknown',
            Str::contains($userAgent, 'Android') => 'Android',
            Str::contains($userAgent, ['iPhone', 'iPad', 'iOS']) => 'iOS',
            Str::contains($userAgent, 'Windows') => 'Windows',
            Str::contains($userAgent, ['Macintosh', 'Mac OS']) => 'macOS',
            Str::contains($userAgent, 'Linux') => 'Linux',
            default => 'Other',
        };
    }

    private function deviceName(?string $userAgent, string $platform): string
    {
        return match (true) {
            blank($userAgent) => $platform,
            Str::contains($userAgent, 'Edg') => $platform.' · Edge',
            Str::contains($userAgent, 'Chrome') => $platform.' · Chrome',
            Str::contains($userAgent, 'Firefox') => $platform.' · Firefox',
            Str::contains($userAgent, 'Safari') => $platform.' · Safari',
            default => $platform.' · Browser',
        };
    }
}
