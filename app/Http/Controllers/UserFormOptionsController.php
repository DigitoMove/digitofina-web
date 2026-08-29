<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserFormOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate([
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);
        $user = $request->user();
        $requestedOrganizationId = filled($data['organization_id'] ?? null) ? (int) $data['organization_id'] : null;

        if (! $user->hasGlobalOrganizationAccess() && $requestedOrganizationId && $requestedOrganizationId !== (int) $user->organization_id) {
            abort(403, 'You cannot load assignment options for another organization.');
        }

        $organizationId = $user->hasGlobalOrganizationAccess()
            ? $requestedOrganizationId
            : ($user->organization_id ? (int) $user->organization_id : null);
        $branches = Branch::query()
            ->when($user->hasGlobalOrganizationAccess(), fn ($query) => $query->withoutGlobalScope('tenant'))
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'branches' => $branches,
            'roles' => Role::query()
                ->assignableToOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
