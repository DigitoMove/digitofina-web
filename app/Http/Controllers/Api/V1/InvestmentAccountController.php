<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvestmentAccountRequest;
use App\Http\Resources\InvestmentAccountResource;
use App\Http\Resources\InvestmentTransactionResource;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\InvestorAccount;
use App\Services\InvestmentService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class InvestmentAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports'), 403);

        $accounts = ApiSecurity::scopeTenant(InvestmentAccount::query()->with(['investorAccount', 'client', 'plan']), $request->user())
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::collection(InvestmentAccountResource::collection($accounts), 'Investment accounts retrieved.');
    }

    public function store(StoreInvestmentAccountRequest $request, InvestmentService $investments): JsonResponse
    {
        $investor = InvestorAccount::findOrFail($request->validated('investor_account_id'));
        ApiSecurity::assertTenantAccess($request->user(), $investor);

        try {
            $account = $investments->createInvestment($request->validated(), $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestmentAccountResource($account->load(['investorAccount', 'client', 'plan'])), 'Investment account created.', 201);
    }

    public function show(Request $request, InvestmentAccount $investmentAccount): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports') || $investmentAccount->client_id === $request->user()->client?->id, 403);

        return ApiResponse::resource(new InvestmentAccountResource($investmentAccount->load(['investorAccount', 'client', 'plan'])), 'Investment account retrieved.');
    }

    public function statement(Request $request, InvestmentAccount $investmentAccount): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports') || $investmentAccount->client_id === $request->user()->client?->id, 403);

        $transactions = InvestmentTransaction::query()
            ->where('investment_account_id', $investmentAccount->id)
            ->latest('transaction_date')
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::data([
            'investment' => (new InvestmentAccountResource($investmentAccount->load(['investorAccount', 'client', 'plan'])))->resolve($request),
            'transactions' => InvestmentTransactionResource::collection($transactions)->toResponse($request)->getData(true),
        ], 'Investment statement retrieved.');
    }

    public function approve(Request $request, InvestmentAccount $investmentAccount, InvestmentService $investments): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);
        abort_unless($request->user()->canPerform('approve-transactions'), 403);
        $data = $request->validate([
            'payment_method' => ['sometimes', 'in:cash,mobile_money,bank,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $account = $investments->approveInvestment($investmentAccount, $request->user(), $data['payment_method'] ?? 'cash', $data['narration'] ?? null);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestmentAccountResource($account->load(['investorAccount', 'client', 'plan'])), 'Investment approved.');
    }
}
