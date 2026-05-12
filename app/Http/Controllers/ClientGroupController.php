<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientGroupRequest;
use App\Http\Requests\UpdateClientGroupRequest;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientGroupController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Client::class);

        $groups = ClientGroup::query()
            ->with(['branch', 'chairperson', 'members'])
            ->when($request->search, fn ($query, string $search) => $query->whereAny(['group_number', 'name', 'phone', 'registration_number'], 'like', "%{$search}%"))
            ->when($request->branch_id, fn ($query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($request->status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('client-groups.index', [
            'groups' => $groups,
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Client::class);

        return view('client-groups.create', $this->formData(new ClientGroup(['status' => 'active'])));
    }

    public function store(StoreClientGroupRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $group = ClientGroup::create($request->safe()->except('member_ids') + [
            'organization_id' => $request->user()->organization_id,
        ]);

        $this->syncMembers($group, $request->validated('member_ids', []));
        $auditLogger->record('client_group.created', $group, $group->load('members')->toArray());

        return redirect()->route('client-groups.show', $group)->with('status', 'Client group created.');
    }

    public function show(ClientGroup $clientGroup): View
    {
        Gate::authorize('viewAny', Client::class);

        return view('client-groups.show', ['group' => $clientGroup->load(['branch', 'chairperson', 'secretary', 'treasurer', 'members'])]);
    }

    public function edit(ClientGroup $clientGroup): View
    {
        Gate::authorize('create', Client::class);

        return view('client-groups.edit', $this->formData($clientGroup->load('members')));
    }

    public function update(UpdateClientGroupRequest $request, ClientGroup $clientGroup, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $old = $clientGroup->load('members')->toArray();
        $clientGroup->update($request->safe()->except('member_ids'));
        $this->syncMembers($clientGroup, $request->validated('member_ids', []));
        $auditLogger->record('client_group.updated', $clientGroup, $clientGroup->fresh()->load('members')->toArray(), $old);

        return redirect()->route('client-groups.show', $clientGroup)->with('status', 'Client group updated.');
    }

    /**
     * @return array{group: ClientGroup, branches: Collection<int, Branch>, clients: Collection<int, Client>}
     */
    private function formData(ClientGroup $group): array
    {
        return [
            'group' => $group,
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'clients' => Client::where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(),
        ];
    }

    /**
     * @param  array<int, int|string>  $memberIds
     */
    private function syncMembers(ClientGroup $group, array $memberIds): void
    {
        $payload = collect($memberIds)
            ->unique()
            ->mapWithKeys(fn (int|string $clientId): array => [(int) $clientId => [
                'role' => 'member',
                'joined_at' => now()->toDateString(),
                'status' => 'active',
            ]])
            ->all();

        $group->members()->sync($payload);
    }
}
