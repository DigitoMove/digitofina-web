<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        abort_if($request->user()->isMember(), 403);

        $search = trim((string) $request->query('search', ''));
        $users = $this->visibleStaffQuery($request->user())
            ->with(['branch', 'organization', 'role'])
            ->when($search !== '', fn (Builder $query): Builder => $this->applySearch($query, $search))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('staff-directory.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function show(Request $request, User $staff): View
    {
        abort_if($request->user()->isMember(), 403);
        abort_unless($this->visibleStaffQuery($request->user())->whereKey($staff->id)->exists(), 403);

        $staff->load(['branch', 'organization', 'role']);

        return view('staff-directory.show', [
            'staff' => $staff,
            'assignedClients' => Client::query()
                ->with('branch')
                ->where('officer_id', $staff->id)
                ->latest('registered_at')
                ->limit(6)
                ->get(),
            'assignedLoans' => Loan::query()
                ->with(['branch', 'client', 'product'])
                ->where('officer_id', $staff->id)
                ->latest('application_date')
                ->limit(6)
                ->get(),
        ]);
    }

    /**
     * @return Builder<User>
     */
    private function visibleStaffQuery(User $actor): Builder
    {
        $organizationId = $actor->organization_id ?: $actor->branch?->organization_id;

        return User::query()
            ->whereDoesntHave('role', fn (Builder $query): Builder => $query->where('slug', 'member'))
            ->when(! $actor->hasRole('super-admin'), fn (Builder $query): Builder => $query->whereDoesntHave('role', fn (Builder $roleQuery): Builder => $roleQuery->where('slug', 'super-admin')))
            ->when(! $actor->hasRole('super-admin') && $organizationId, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->when(! $actor->hasRole('super-admin') && ! $organizationId && $actor->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $actor->branch_id));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->whereAny(['name', 'email', 'phone', 'position', 'status'], 'like', "%{$search}%")
                ->orWhereHas('role', fn (Builder $roleQuery): Builder => $roleQuery->where('name', 'like', "%{$search}%"))
                ->orWhereHas('branch', fn (Builder $branchQuery): Builder => $branchQuery->where('name', 'like', "%{$search}%"))
                ->orWhereHas('organization', fn (Builder $organizationQuery): Builder => $organizationQuery->where('name', 'like', "%{$search}%"));
        });
    }
}
