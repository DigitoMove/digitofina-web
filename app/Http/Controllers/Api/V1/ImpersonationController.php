<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('super-admin') && $request->user()->is_active && $request->user()->status === 'active', 403);
        $request->validate(['search' => ['nullable', 'string', 'max:191'], 'role' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();
        $users = User::query()
            ->with(['role', 'organization', 'branch' => fn (BelongsTo $query): BelongsTo => $query->withoutGlobalScope('tenant')])
            ->where('is_active', true)->where('status', 'active')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', '!=', 'super-admin'))
            ->whereHas('organization', fn (Builder $query): Builder => $query->where('status', 'active')->where(fn (Builder $query): Builder => $query->where('is_demo', false)->orWhereNull('demo_expires_at')->orWhere('demo_expires_at', '>', now())))
            ->when($request->filled('role'), fn (Builder $query): Builder => $query->whereHas('role', fn (Builder $query): Builder => $query->where('slug', $request->string('role')->toString())))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereAny(['name', 'email'], 'like', "%{$search}%")->orWhereHas('organization', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"))))
            ->orderBy('name')->orderBy('id')->paginate(25);

        return ApiResponse::data([
            'users' => $users->getCollection()->map(fn (User $user): array => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->role?->slug, 'role_name' => $user->role?->name,
                'organization' => $user->organization?->name, 'branch' => $user->branch?->name,
            ]),
            'current_page' => $users->currentPage(), 'last_page' => $users->lastPage(),
        ]);
    }
}
