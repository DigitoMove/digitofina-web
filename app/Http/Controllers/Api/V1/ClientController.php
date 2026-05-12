<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\SavingsAccountResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        $clients = Client::query()
            ->with(['branch', 'officer'])
            ->when($request->string('search')->toString(), fn ($query, string $search) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone', 'national_id'], 'like', "%{$search}%"))
            ->when($request->integer('branch_id'), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request, ClientService $clients): ClientResource
    {
        Gate::authorize('create', Client::class);

        return new ClientResource($clients->register($this->payload($request), $request->user(), $request->file('profile_photo'))->load(['branch', 'officer']));
    }

    public function show(Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return new ClientResource($client->load(['branch', 'officer']));
    }

    public function update(UpdateClientRequest $request, Client $client, ClientService $clients): ClientResource
    {
        Gate::authorize('update', $client);

        return new ClientResource($clients->update($client, $this->payload($request), $request->user(), $request->file('profile_photo'))->load(['branch', 'officer']));
    }

    public function accounts(Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        return SavingsAccountResource::collection($client->savingsAccounts()->with(['client', 'branch', 'product'])->paginate(request()->integer('per_page', 20)));
    }

    public function loans(Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        return LoanResource::collection($client->loans()->with(['client', 'product'])->paginate(request()->integer('per_page', 20)));
    }

    public function statements(Client $client): AnonymousResourceCollection
    {
        Gate::authorize('view', $client);

        $transactions = $client->savingsAccounts()
            ->with('transactions.account')
            ->get()
            ->pluck('transactions')
            ->flatten()
            ->sortByDesc('transaction_date')
            ->values();

        return SavingsTransactionResource::collection($transactions);
    }

    public function destroy(Client $client): Response
    {
        abort(405);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StoreClientRequest|UpdateClientRequest $request): array
    {
        $data = $request->safe()->except(['profile_photo', 'kyc_checklist']);
        $checklist = collect(['identity_confirmed', 'address_confirmed', 'photo_captured', 'signature_captured', 'next_of_kin_confirmed'])
            ->mapWithKeys(fn (string $key): array => [$key => in_array($key, $request->validated('kyc_checklist', []), true)])
            ->all();

        return $data + ['kyc_documents' => $checklist];
    }
}
