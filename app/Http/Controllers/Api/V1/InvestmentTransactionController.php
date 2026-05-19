<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvestmentTransactionRequest;
use App\Http\Resources\InvestmentTransactionResource;
use App\Models\InvestmentAccount;
use App\Services\InvestmentService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class InvestmentTransactionController extends Controller
{
    public function topUp(StoreInvestmentTransactionRequest $request, InvestmentAccount $investmentAccount, InvestmentService $investments): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $transaction = $investments->topUp($investmentAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method', 'cash'), $request->validated('narration'), $request->validated('idempotency_key'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestmentTransactionResource($transaction->load(['investorAccount', 'investmentAccount'])), 'Investment top-up posted.', 201);
    }

    public function withdraw(StoreInvestmentTransactionRequest $request, InvestmentAccount $investmentAccount, InvestmentService $investments): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $transaction = $investments->withdraw($investmentAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method', 'cash'), $request->validated('narration'), $request->validated('idempotency_key'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestmentTransactionResource($transaction->load(['investorAccount', 'investmentAccount'])), 'Investment withdrawal posted.', 201);
    }

    public function postReturn(StoreInvestmentTransactionRequest $request, InvestmentAccount $investmentAccount, InvestmentService $investments): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $transaction = $investments->postReturn($investmentAccount, (float) $request->validated('amount'), $request->user(), $request->validated('narration'), $request->validated('idempotency_key'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestmentTransactionResource($transaction->load(['investorAccount', 'investmentAccount'])), 'Investment return posted.', 201);
    }

    public function reinvest(StoreInvestmentTransactionRequest $request, InvestmentAccount $investmentAccount, InvestmentService $investments): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $transaction = $investments->reinvest($investmentAccount, (float) $request->validated('amount'), $request->user(), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestmentTransactionResource($transaction->load(['investorAccount', 'investmentAccount'])), 'Investment return reinvested.', 201);
    }
}
