<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Http\Requests\StoreSavingsTransferRequest;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\FinancialControlService;
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

    public function withdraw(StoreSavingsTransactionRequest $request, SavingsAccount $savingsAccount, SavingsTransactionService $transactions, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);
        $amount = (float) $request->validated('amount');

        if ($controls->requiresMakerChecker($request->user(), 'savings_withdrawal', $amount)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'savings_withdrawal',
                subject: $savingsAccount,
                payload: [
                    'amount' => $amount,
                    'payment_method' => $request->validated('payment_method'),
                    'narration' => $request->validated('narration'),
                    'transaction_date' => today()->toDateString(),
                ],
                amount: $amount,
                requiredPermission: 'approve-transactions',
                reason: $request->validated('narration'),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Withdrawal submitted for approval.', 202);
        }

        try {
            $transaction = $transactions->withdraw($savingsAccount, $amount, $request->user(), $request->validated('payment_method'), $request->validated('narration'));
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

    public function reverse(Request $request, SavingsTransaction $savingsTransaction, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsTransaction);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'savings_transaction_reversal',
                subject: $savingsTransaction,
                payload: ['reason' => $data['reason']],
                amount: (float) $savingsTransaction->amount,
                requiredPermission: 'approve-reversals',
                reason: $data['reason'],
                oldValues: $savingsTransaction->only(['status', 'balance_before', 'balance_after']),
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Transaction reversal submitted for approval.', 202);
    }
}
