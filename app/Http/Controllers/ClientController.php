<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountNumberGenerator;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Client::class);

        $clients = Client::query()
            ->with(['branch', 'officer'])
            ->when($request->search, fn ($query, $search) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone', 'national_id'], 'like', "%{$search}%"))
            ->when($request->branch_id, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->officer_id, fn ($query, $officerId) => $query->where('officer_id', $officerId))
            ->when($request->client_type, fn ($query, $type) => $query->where('client_type', $type))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'branches' => Branch::orderBy('name')->get(),
            'officers' => User::whereHas('role', fn ($query) => $query->whereIn('slug', ['officer', 'loan-officer', 'field-officer']))->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Client::class);

        return view('clients.create', ['client' => new Client(['status' => 'active', 'kyc_status' => 'pending', 'client_type' => 'individual']), 'branches' => Branch::where('is_active', true)->get()]);
    }

    public function store(StoreClientRequest $request, AccountNumberGenerator $numbers, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $data = $this->clientPayload($request);

        $branch = Branch::findOrFail($data['branch_id']);
        $memberUser = ($data['email'] ?? null)
            ? User::firstOrCreate(['email' => $data['email']], [
                'organization_id' => auth()->user()->organization_id,
                'branch_id' => $branch->id,
                'role_id' => Role::where('slug', 'member')->value('id'),
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'phone' => $data['phone'],
                'position' => 'Member',
                'status' => 'active',
                'password' => Hash::make(Str::password(12)),
            ])
            : null;

        $client = Client::create($data + [
            'organization_id' => auth()->user()->organization_id,
            'officer_id' => auth()->id(),
            'user_id' => $memberUser?->id,
            'client_number' => $numbers->clientNumber($branch),
            'registered_at' => now()->toDateString(),
        ]);

        $this->storeProfilePhoto($request, $client);
        $auditLogger->record('client.created', $client, $client->toArray());

        return redirect()->route('clients.show', $client)->with('status', 'Client registered.');
    }

    public function show(Client $client): View
    {
        Gate::authorize('view', $client);

        $client->load(['branch', 'officer', 'accounts.accountType', 'loans.product', 'documents.uploadedBy', 'documents.verifiedBy', 'notes.user', 'groups']);

        return view('clients.show', compact('client'));
    }

    public function edit(Client $client): View
    {
        Gate::authorize('update', $client);

        return view('clients.edit', ['client' => $client, 'branches' => Branch::where('is_active', true)->get()]);
    }

    public function update(UpdateClientRequest $request, Client $client, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $client);

        $old = $client->toArray();
        $client->update($this->clientPayload($request));
        $this->storeProfilePhoto($request, $client);
        $auditLogger->record('client.updated', $client, $client->fresh()->toArray(), $old);

        return redirect()->route('clients.show', $client)->with('status', 'Client updated.');
    }

    private function clientPayload(StoreClientRequest|UpdateClientRequest $request): array
    {
        $data = $request->safe()->except(['profile_photo', 'kyc_checklist']);
        $checklist = collect(['identity_confirmed', 'address_confirmed', 'photo_captured', 'signature_captured', 'next_of_kin_confirmed'])
            ->mapWithKeys(fn (string $key): array => [$key => in_array($key, $request->validated('kyc_checklist', []), true)])
            ->all();

        return $data + ['kyc_documents' => $checklist];
    }

    private function storeProfilePhoto(StoreClientRequest|UpdateClientRequest $request, Client $client): void
    {
        if (! $request->hasFile('profile_photo')) {
            return;
        }

        $client->update([
            'photo_path' => $request->file('profile_photo')->store("clients/{$client->id}/profile", 'local'),
        ]);
    }
}
