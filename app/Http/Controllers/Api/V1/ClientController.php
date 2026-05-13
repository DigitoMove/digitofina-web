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
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $clients = Client::query()
            ->with(['branch', 'officer'])
            ->when($request->string('search')->toString(), fn ($query, string $search) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone', 'national_id'], 'like', "%{$search}%"))
            ->when($request->integer('branch_id'), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::collection(ClientResource::collection($clients), 'Clients retrieved.');
    }

    public function store(StoreClientRequest $request, ClientService $clients): JsonResponse
    {
        Gate::authorize('create', Client::class);

        return ApiResponse::resource(
            new ClientResource($clients->register($this->payload($request), $request->user(), $request->file('profile_photo'))->load(['branch', 'officer'])),
            'Client registered successfully.',
            201
        );
    }

    public function show(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return ApiResponse::resource(new ClientResource($client->load(['branch', 'officer'])), 'Client profile retrieved.');
    }

    public function update(UpdateClientRequest $request, Client $client, ClientService $clients): JsonResponse
    {
        Gate::authorize('update', $client);

        return ApiResponse::resource(
            new ClientResource($clients->update($client, $this->payload($request), $request->user(), $request->file('profile_photo'))->load(['branch', 'officer'])),
            'Client updated successfully.'
        );
    }

    public function accounts(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return ApiResponse::collection(
            SavingsAccountResource::collection($client->savingsAccounts()->with(['client', 'branch', 'product'])->paginate(request()->integer('per_page', 20))),
            'Client savings accounts retrieved.'
        );
    }

    public function loans(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return ApiResponse::collection(
            LoanResource::collection($client->loans()->with(['client', 'product'])->paginate(request()->integer('per_page', 20))),
            'Client loans retrieved.'
        );
    }

    public function statements(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $transactions = $client->savingsAccounts()
            ->with('transactions.account')
            ->get()
            ->pluck('transactions')
            ->flatten()
            ->sortByDesc('transaction_date')
            ->values();

        return ApiResponse::collection(SavingsTransactionResource::collection($transactions), 'Client statements retrieved.');
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
