<?php

namespace App\Http\Controllers;

use App\Models\TrafficVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrafficHeartbeatController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $data = $request->validate(['visit_id' => ['required', 'integer', 'min:1']]);
        if (! config('traffic.enabled') || ! $request->session()->has('traffic_visitor') || ! Schema::hasTable('traffic_visits')) {
            return response()->noContent();
        }
        DB::transaction(function () use ($request, $data): void {
            $visit = TrafficVisit::whereKey($data['visit_id'])
                ->where('visitor_hash', hash('sha256', $request->session()->get('traffic_visitor')))
                ->where('host', strtolower($request->getHost()))->lockForUpdate()->first();
            if (! $visit || $visit->last_seen_at->lt(now()->subMinutes(30))) {
                return;
            }
            $elapsed = max(0, (int) $visit->last_seen_at->diffInSeconds(now()));
            $visit->update([
                'active_seconds' => $visit->active_seconds + min(30, $elapsed),
                'last_seen_at' => now(),
            ]);
        });

        return response()->noContent();
    }
}
