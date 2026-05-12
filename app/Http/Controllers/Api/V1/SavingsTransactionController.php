<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Http\Requests\StoreSavingsTransferRequest;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\SavingsTransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

class SavingsTransactionController extends Controller
{
    public function deposit(StoreSavingsTransactionRequest $request, SavingsAccount $savingsAccount, SavingsTransactionService $transactions): SavingsTransactionResource
    {
        try {
            $transaction = $transactions->deposit($savingsAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method'), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return new SavingsTransactionResource($transaction->load(['account', 'teller']));
    }

    public function withdraw(StoreSavingsTransactionRequest $request, SavingsAccount $savingsAccount, SavingsTransactionService $transactions): SavingsTransactionResource
    {
        try {
            $transaction = $transactions->withdraw($savingsAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method'), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return new SavingsTransactionResource($transaction->load(['account', 'teller']));
    }

    public function transfer(StoreSavingsTransferRequest $request, SavingsTransactionService $transactions): JsonResource
    {
        try {
            $posted = $transactions->transfer(
                SavingsAccount::findOrFail($request->validated('from_savings_account_id')),
                SavingsAccount::findOrFail($request->validated('to_savings_account_id')),
                (float) $request->validated('amount'),
                $request->user(),
                $request->validated('narration'),
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return new JsonResource([
            'out' => new SavingsTransactionResource($posted['out']->load(['account', 'teller'])),
            'in' => new SavingsTransactionResource($posted['in']->load(['account', 'teller'])),
        ]);
    }

    public function reverse(Request $request, SavingsTransaction $savingsTransaction, SavingsTransactionService $transactions): SavingsTransactionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $transaction = $transactions->reverse($savingsTransaction, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return new SavingsTransactionResource($transaction->load(['account', 'teller']));
    }
}
