<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SystemActivityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemActivityController extends Controller
{
    public function index(Request $request, SystemActivityService $activity): View
    {
        $viewer = $request->user();
        $this->authorizeViewer($viewer);
        $organizationId = $viewer->hasRole('organization-admin') ? $viewer->organization_id : null;

        return view('system-activity.index', [
            'summary' => $activity->summary($organizationId),
            'onlineUsers' => $activity->groupedRecent($organizationId),
            'organizationId' => $organizationId,
        ]);
    }

    public function show(Request $request, User $user, SystemActivityService $activity): View
    {
        $viewer = $request->user();
        $this->authorizeViewer($viewer);
        abort_if($viewer->hasRole('organization-admin') && $user->organization_id !== $viewer->organization_id, 404);

        return view('system-activity.show', [
            'trackedUser' => $user->load(['role', 'organization', 'branch', 'client']),
            'activities' => $activity->activities($viewer->hasRole('organization-admin') ? $viewer->organization_id : null)
                ->where('user_id', $user->id)->latest('last_seen_at')->get(),
        ]);
    }

    private function authorizeViewer(?User $user): void
    {
        abort_unless($user?->hasAnyRole(['super-admin', 'organization-admin']), 403);
    }
}
