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
use App\Models\SavingsTransaction;
use App\Services\ClientService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $clientsQuery = ApiSecurity::scopeTenant(Client::query(), $request->user())
            ->with(['branch', 'officer'])
            ->when($request->integer('branch_id'), function ($query, int $branchId) use ($request): void {
                ApiSecurity::assertClientBranchAccess($request->user(), $branchId);
            });

        $clients = ApiQuery::apply(
            $clientsQuery,
            $request,
            ['client_number', 'first_name', 'last_name', 'phone', 'national_id'],
            ['branch_id', 'status', 'client_type', 'kyc_status', 'risk_rating', 'officer_id'],
            ['id', 'client_number', 'first_name', 'last_name', 'status', 'registered_at', 'created_at', 'updated_at'],
        )->paginate(ApiQuery::perPage($request, 15));

        return ApiResponse::collection(ClientResource::collection($clients), 'Clients retrieved.', meta: ApiQuery::meta($request));
    }

    public function store(StoreClientRequest $request, ClientService $clients): JsonResponse
    {
        Gate::authorize('create', Client::class);
        ApiSecurity::assertClientBranchAccess($request->user(), (int) $request->validated('branch_id'));

        return ApiResponse::resource(
            new ClientResource($clients->register($this->payload($request), $request->user(), $request->file('profile_photo'))->load(['branch', 'officer'])),
            'Client registered successfully.',
            201
        );
    }

    public function show(Client $client): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $client);
        Gate::authorize('view', $client);

        return ApiResponse::resource(new ClientResource($client->load(['branch', 'officer'])), 'Client profile retrieved.');
    }

    public function update(UpdateClientRequest $request, Client $client, ClientService $clients): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $client);
        Gate::authorize('update', $client);
        ApiSecurity::assertClientBranchAccess($request->user(), (int) $request->validated('branch_id'));

        return ApiResponse::resource(
            new ClientResource($clients->update($client, $this->payload($request), $request->user(), $request->file('profile_photo'))->load(['branch', 'officer'])),
            'Client updated successfully.'
        );
    }

    public function accounts(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return ApiResponse::collection(
            SavingsAccountResource::collection(ApiQuery::apply(
                $client->savingsAccounts()->getQuery()->with(['client', 'branch', 'product']),
                request(),
                ['account_number'],
                ['status', 'savings_product_id'],
                ['id', 'account_number', 'status', 'opening_date', 'created_at', 'updated_at'],
            )->paginate(ApiQuery::perPage(request(), 20))),
            'Client savings accounts retrieved.',
            meta: ApiQuery::meta(request())
        );
    }

    public function loans(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return ApiResponse::collection(
            LoanResource::collection(ApiQuery::apply(
                $client->loans()->getQuery()->with(['client', 'product']),
                request(),
                ['loan_number', 'purpose'],
                ['status', 'loan_product_id'],
                ['id', 'loan_number', 'status', 'application_date', 'created_at', 'updated_at'],
            )->paginate(ApiQuery::perPage(request(), 20))),
            'Client loans retrieved.',
            meta: ApiQuery::meta(request())
        );
    }

    public function statements(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $transactions = ApiQuery::apply(
            SavingsTransaction::query()
                ->with('account')
                ->where('client_id', $client->id),
            request(),
            ['reference', 'narration'],
            ['type', 'status', 'payment_method'],
            ['id', 'reference', 'type', 'amount', 'transaction_date', 'created_at', 'updated_at'],
            'transaction_date',
        )->paginate(ApiQuery::perPage(request(), 30));

        return ApiResponse::collection(SavingsTransactionResource::collection($transactions), 'Client statements retrieved.', meta: ApiQuery::meta(request()));
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
