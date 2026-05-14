<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Http\Requests\StoreSavingsTransferRequest;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\SavingsTransactionService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SavingsTransactionController extends Controller
{
    public function deposit(StoreSavingsTransactionRequest $request, SavingsAccount $savingsAccount, SavingsTransactionService $transactions): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);

        try {
            $transaction = $transactions->deposit($savingsAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method'), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new SavingsTransactionResource($transaction->load(['account', 'teller'])), 'Deposit posted successfully.', 201);
    }

    public function withdraw(StoreSavingsTransactionRequest $request, SavingsAccount $savingsAccount, SavingsTransactionService $transactions): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);

        try {
            $transaction = $transactions->withdraw($savingsAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method'), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new SavingsTransactionResource($transaction->load(['account', 'teller'])), 'Withdrawal posted successfully.', 201);
    }

    public function transfer(StoreSavingsTransferRequest $request, SavingsTransactionService $transactions): JsonResponse
    {
        try {
            $fromAccount = SavingsAccount::findOrFail($request->validated('from_savings_account_id'));
            $toAccount = SavingsAccount::findOrFail($request->validated('to_savings_account_id'));
            ApiSecurity::assertTenantAccess($request->user(), $fromAccount);
            ApiSecurity::assertTenantAccess($request->user(), $toAccount);

            $posted = $transactions->transfer(
                $fromAccount,
                $toAccount,
                (float) $request->validated('amount'),
                $request->user(),
                $request->validated('narration'),
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::data([
            'out' => (new SavingsTransactionResource($posted['out']->load(['account', 'teller'])))->resolve($request),
            'in' => (new SavingsTransactionResource($posted['in']->load(['account', 'teller'])))->resolve($request),
        ], 'Transfer posted successfully.', 201);
    }

    public function reverse(Request $request, SavingsTransaction $savingsTransaction, SavingsTransactionService $transactions): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsTransaction);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $transaction = $transactions->reverse($savingsTransaction, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new SavingsTransactionResource($transaction->load(['account', 'teller'])), 'Transaction reversed successfully.');
    }
}
