<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientDeletionService;
use App\Services\ClientService;
use App\Services\FinancialRelationshipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Client::class);
        $user = $request->user();
        $canViewAllBranches = $user->hasRole('super-admin') || $user->canPerform('manage-organization') || $user->canPerform('audit-system');
        $canBrowseClientList = $canViewAllBranches || $user->hasRole('supervisor') || $user->canPerform('manage-users') || $user->canPerform('disburse-loans');

        $hasClientSearch = $request->filled('search')
            || $request->filled('branch_id')
            || $request->filled('status')
            || $request->filled('officer_id')
            || $request->filled('client_type');

        $selectedBranchId = $canViewAllBranches
            ? $request->integer('branch_id')
            : (int) $user->branch_id;

        $clients = Client::query()
            ->with(['branch', 'officer'])
            ->when(! $canBrowseClientList && ! $hasClientSearch, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($request->search, fn ($query, $search) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone', 'national_id'], 'like', "%{$search}%"))
            ->when($selectedBranchId, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->officer_id, fn ($query, $officerId) => $query->where('officer_id', $officerId))
            ->when($request->client_type, fn ($query, $type) => $query->where('client_type', $type))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'hasClientSearch' => $hasClientSearch,
            'canBrowseClientList' => $canBrowseClientList,
            'canViewAllBranches' => $canViewAllBranches,
            'branches' => Branch::query()
                ->when(! $canViewAllBranches && $user->branch_id, fn ($query) => $query->whereKey($user->branch_id))
                ->orderBy('name')
                ->get(),
            'officers' => User::whereHas('role', fn ($query) => $query->where('slug', 'loan-officer'))
                ->when(! $canViewAllBranches && $user->branch_id, fn ($query) => $query->where('branch_id', $user->branch_id))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Client::class);

        return view('clients.create', [
            'client' => new Client([
                'status' => 'active',
                'kyc_status' => 'pending',
                'client_type' => 'individual',
                'branch_id' => $request->user()->branch_id,
            ]),
            'branches' => $this->assignableBranches($request)->get(),
        ]);
    }

    public function store(StoreClientRequest $request, ClientService $clients): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $client = $clients->register($this->clientPayload($request), $request->user(), $request->file('profile_photo'));

        return redirect()->route('clients.show', $client)->with('status', 'Client registered.');
    }

    public function show(Client $client, FinancialRelationshipService $financialRelationships): View
    {
        Gate::authorize('view', $client);

        $client->load([
            'branch',
            'officer',
            'accounts.accountType',
            'savingsAccounts.product',
            'investmentAccounts.plan',
            'shareAccounts.product',
            'loans.branch',
            'loans.loanAccount.accountType',
            'loans.product',
            'documents.uploadedBy',
            'documents.verifiedBy',
            'notes.user',
            'groups',
        ]);

        return view('clients.show', [
            'client' => $client,
            'financialOverview' => $financialRelationships->clientOverview($client),
            'canAssignClientOfficer' => $this->canAssignClientOfficer(request()),
            'assignableOfficers' => $this->assignableOfficers(request(), $client->branch_id)->get(),
        ]);
    }

    public function accounts(Client $client): View
    {
        Gate::authorize('view', $client);

        $client->load([
            'branch',
            'accounts.accountType',
            'savingsAccounts.product',
            'loans.branch',
            'loans.loanAccount.accountType',
            'loans.product',
        ]);

        return view('clients.accounts', compact('client'));
    }

    public function savingsAccounts(Client $client): View
    {
        Gate::authorize('view', $client);

        $client->load(['branch', 'accounts.accountType', 'savingsAccounts.product']);

        return view('clients.savings-accounts', compact('client'));
    }

    public function loans(Client $client): View
    {
        Gate::authorize('view', $client);

        $client->load([
            'branch',
            'loans.branch',
            'loans.loanAccount.accountType',
            'loans.product',
            'loans.officer',
        ]);

        return view('clients.loans', compact('client'));
    }

    public function edit(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('clients.edit', ['client' => $client, 'branches' => $this->assignableBranches(request())->get()]);
    }

    public function update(UpdateClientRequest $request, Client $client, ClientService $clients): RedirectResponse
    {
        Gate::authorize('update', $client);

        $clients->update($client, $this->clientPayload($request), $request->user(), $request->file('profile_photo'));

        return redirect()->route('clients.show', $client)->with('status', 'Client updated.');
    }

    public function assignOfficer(Request $request, Client $client, ClientService $clients): RedirectResponse
    {
        abort_unless($this->canAssignClientOfficer($request), 403);

        $data = $request->validate([
            'officer_id' => ['required', 'exists:users,id'],
        ]);

        try {
            $clients->assignOfficer($client, $request->user(), (int) $data['officer_id']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['client_officer_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Client officer updated.');
    }

    public function destroy(Request $request, Client $client, ClientDeletionService $deletions): RedirectResponse
    {
        Gate::authorize('delete', $client);

        $data = $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        if ($data['confirmation'] !== $client->client_number) {
            return back()->with('warning', 'Type the exact client number before deleting this client.');
        }

        $deletions->delete($client);

        return redirect()->route('clients.index')->with('status', 'Client and all connected records deleted.');
    }

    private function clientPayload(StoreClientRequest|UpdateClientRequest $request): array
    {
        $data = $request->safe()->except(['profile_photo', 'kyc_checklist']);
        $checklist = collect(['identity_confirmed', 'address_confirmed', 'photo_captured', 'signature_captured', 'next_of_kin_confirmed'])
            ->mapWithKeys(fn (string $key): array => [$key => in_array($key, $request->validated('kyc_checklist', []), true)])
            ->all();

        return $data + ['kyc_documents' => $checklist];
    }

    /**
     * @return Builder<Branch>
     */
    private function assignableBranches(Request $request): Builder
    {
        return Branch::query()
            ->where('is_active', true)
            ->when(! $request->user()->canPerform('manage-organization') && ! $request->user()->hasRole('super-admin'), fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
            ->orderBy('name');
    }

    /**
     * @return Builder<User>
     */
    private function assignableOfficers(Request $request, ?int $branchId = null): Builder
    {
        return User::query()
            ->with(['branch', 'role'])
            ->where('status', 'active')
            ->when($request->user()->organization_id, fn (Builder $query, int $organizationId): Builder => $query->where('organization_id', $organizationId))
            ->when($branchId && ! $request->user()->canPerform('manage-organization') && ! $request->user()->hasRole('super-admin'), fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', ['branch-manager', 'loan-officer']))
            ->orderBy('name');
    }

    private function canAssignClientOfficer(Request $request): bool
    {
        return $request->user()->hasRole('super-admin')
            || $request->user()->canPerform('manage-users')
            || $request->user()->canPerform('disburse-loans')
            || $request->user()->canPerform('manage-organization');
    }
}
