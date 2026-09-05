<?php

namespace App\Http\Controllers;

use App\Models\BranchCashReconciliation;
use App\Models\OperationalRun;
use App\Services\OperationsMonitor;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OperationsHealthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, OperationsMonitor $monitor): View
    {
        $user = $request->user();
        abort_unless(! $user->isMember() && $user->hasAnyRole(['super-admin', 'organization-admin']), 403);
        $organizationId = app(TenantContext::class)->id() ?: $user->organization_id;
        $global = $user->hasRole('super-admin') && ! app(TenantContext::class)->isScoped();
        abort_unless($global || $organizationId, 403);
        $heartbeat = OperationalRun::query()->where('task', 'scheduler-heartbeat')->latest('finished_at')->first();
        $runs = OperationalRun::query()->where('task', '!=', 'scheduler-heartbeat')
            ->when(! $global, fn ($query) => $query->where('organization_id', $organizationId))
            ->latest('id')->paginate(20);
        $variances = BranchCashReconciliation::query()->with('branch')
            ->when(! $global, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('variance', '!=', 0)->whereDate('business_date', '>=', today()->subDays(30))
            ->latest('business_date')->limit(20)->get();

        return view('operations-health', [
            'global' => $global, 'runs' => $runs, 'variances' => $variances,
            'heartbeat' => $heartbeat?->finished_at,
            'schedulerHealthy' => $heartbeat?->finished_at?->gte(now()->subMinutes(5)) ?? false,
            'tasks' => $global ? $monitor->dailyStatuses() : [],
            'backupConfigured' => $global ? array_key_exists('backup:run', Artisan::all()) : null,
            'failedJobs' => $global && in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)
                ? DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count() : null,
        ]);
    }
}
