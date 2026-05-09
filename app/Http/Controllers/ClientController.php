<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountNumberGenerator;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $clients = Client::query()
            ->with('branch')
            ->when($request->search, fn ($query, $search) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone', 'national_id'], 'like', "%{$search}%"))
            ->latest()
            ->paginate(15);

        return view('clients.index', compact('clients'));
    }

    public function create(): View
    {
        return view('clients.create', ['branches' => Branch::where('is_active', true)->get()]);
    }

    public function store(Request $request, AccountNumberGenerator $numbers, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'national_id' => ['nullable', 'string', 'max:80', 'unique:clients,national_id'],
            'address' => ['nullable', 'string'],
            'next_of_kin_name' => ['nullable', 'string', 'max:191'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:40'],
            'employment_status' => ['nullable', 'string', 'max:191'],
            'business_name' => ['nullable', 'string', 'max:191'],
            'business_details' => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $memberUser = $data['email']
            ? User::firstOrCreate(['email' => $data['email']], [
                'branch_id' => $branch->id,
                'role_id' => Role::where('slug', 'member')->value('id'),
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'phone' => $data['phone'],
                'position' => 'Member',
                'password' => Hash::make(Str::password(12)),
            ])
            : null;

        $client = Client::create($data + [
            'officer_id' => auth()->id(),
            'user_id' => $memberUser?->id,
            'client_number' => $numbers->clientNumber($branch),
            'registered_at' => now()->toDateString(),
        ]);

        $auditLogger->record('client.created', $client, $client->toArray());

        return redirect()->route('clients.show', $client)->with('status', 'Client registered.');
    }

    public function show(Client $client): View
    {
        $client->load(['branch', 'accounts.accountType', 'loans.product']);

        return view('clients.show', compact('client'));
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', ['client' => $client, 'branches' => Branch::where('is_active', true)->get()]);
    }

    public function update(Request $request, Client $client, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'national_id' => ['nullable', 'string', 'max:80', 'unique:clients,national_id,'.$client->id],
            'address' => ['nullable', 'string'],
            'next_of_kin_name' => ['nullable', 'string', 'max:191'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:40'],
            'employment_status' => ['nullable', 'string', 'max:191'],
            'business_name' => ['nullable', 'string', 'max:191'],
            'business_details' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
            'kyc_status' => ['required', 'in:pending,verified,rejected'],
        ]);

        $old = $client->toArray();
        $client->update($data);
        $auditLogger->record('client.updated', $client, $client->fresh()->toArray(), $old);

        return redirect()->route('clients.show', $client)->with('status', 'Client updated.');
    }
}
