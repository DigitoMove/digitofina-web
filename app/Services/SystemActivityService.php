<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\UserActivity;
use App\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SystemActivityService
{
    public const ONLINE_MINUTES = 5;

    public const CLIENT_VISIT_DAYS = 30;

    /**
     * @return array{active_staff:int, online_staff:int, active_devices:int, total_clients:int, visiting_clients:int, online_minutes:int, visit_days:int}
     */
    public function summary(?int $organizationId = null): array
    {
        $activities = $this->activities($organizationId);
        $onlineCutoff = now()->subMinutes(self::ONLINE_MINUTES);
        $visitCutoff = now()->subDays(self::CLIENT_VISIT_DAYS);
        $staff = User::query()->withoutGlobalScopes()->staff()->where('status', 'active')->where('is_active', true);
        $clients = Client::query()->withoutGlobalScopes()->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId));
        $staffIds = $staff->pluck('id');
        $onlineStaff = (clone $activities)->whereIn('user_id', $staffIds)->where('last_seen_at', '>=', $onlineCutoff)->distinct()->count('user_id');
        $activeDevices = (clone $activities)->whereIn('user_id', $staffIds)->distinct()->count('device_key');
        $clientIds = User::query()->withoutGlobalScopes()->whereHas('role', fn ($query) => $query->where('slug', RoleCatalog::MEMBER_SLUG))->pluck('id');
        $visitingClientIds = (clone $activities)->whereIn('user_id', $clientIds)->where('last_seen_at', '>=', $visitCutoff)->distinct()->pluck('user_id');

        return [
            'active_staff' => $staff->count(),
            'online_staff' => $onlineStaff,
            'active_devices' => $activeDevices,
            'total_clients' => $clients->count(),
            'visiting_clients' => Client::query()->withoutGlobalScopes()->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))->whereIn('user_id', $visitingClientIds)->count(),
            'online_minutes' => self::ONLINE_MINUTES,
            'visit_days' => self::CLIENT_VISIT_DAYS,
        ];
    }

    public function activities(?int $organizationId = null): Builder
    {
        return UserActivity::query()->with(['user.role', 'user.branch', 'user.client', 'organization'])
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId));
    }

    public function groupedRecent(?int $organizationId = null): Collection
    {
        return $this->activities($organizationId)->where('last_seen_at', '>=', now()->subMinutes(self::ONLINE_MINUTES))->latest('last_seen_at')->get()->groupBy('user_id')->map(function (Collection $records): array {
            $latest = $records->first();

            return ['user' => $latest->user, 'activity' => $latest, 'device_count' => $records->pluck('device_key')->unique()->count(), 'sessions' => $records->count()];
        })->values();
    }
}
