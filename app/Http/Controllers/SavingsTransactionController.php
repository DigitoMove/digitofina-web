<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Http\Requests\StoreSavingsTransferRequest;
use App\Models\SavingsAccount;
use App\Services\SavingsTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class SavingsTransactionController extends Controller
{
    public function depositForm(Request $request, int $savingsAccountId, SavingsTransactionService $service): View
    {
        $savingsAccount = $service->resolveTransactionAccount($savingsAccountId, $request->user());

        return view('savings-transactions.deposit', ['account' => $savingsAccount->load(['client', 'product'])]);
    }

    public function deposit(StoreSavingsTransactionRequest $request, int $savingsAccountId, SavingsTransactionService $service): RedirectResponse
    {
        $savingsAccount = $service->resolveTransactionAccount($savingsAccountId, $request->user());

        try {
            $transaction = $service->deposit($savingsAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method'), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('savings-transactions.receipt', $transaction)->with('status', 'Savings deposit posted.');
    }

    public function withdrawalForm(Request $request, int $savingsAccountId, SavingsTransactionService $service): View
    {
        $savingsAccount = $service->resolveWithdrawalAccount($savingsAccountId, $request->user());

        return view('savings-transactions.withdrawal', ['account' => $savingsAccount]);
    }

    public function withdrawal(StoreSavingsTransactionRequest $request, int $savingsAccountId, SavingsTransactionService $service): RedirectResponse
    {
        $savingsAccount = $service->resolveWithdrawalAccount($savingsAccountId, $request->user());

        try {
            $transaction = $service->withdraw($savingsAccount, (float) $request->validated('amount'), $request->user(), $request->validated('payment_method'), $request->validated('narration'));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('savings-transactions.receipt', $transaction)->with('status', 'Savings withdrawal posted.');
    }

    public function transferForm(Request $request): View
    {
        return view('savings-transactions.transfer', [
            'accounts' => SavingsAccount::with(['client', 'product'])->where('status', 'active')->orderBy('account_number')->get(),
            'fromAccountId' => $request->integer('from'),
        ]);
    }

    public function transfer(StoreSavingsTransferRequest $request, SavingsTransactionService $service): RedirectResponse
    {
        try {
            $transactions = $service->transfer(
                SavingsAccount::findOrFail($request->validated('from_savings_account_id')),
                SavingsAccount::findOrFail($request->validated('to_savings_account_id')),
                (float) $request->validated('amount'),
                $request->user(),
                $request->validated('narration'),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('savings-transactions.receipt', $transactions['out'])->with('status', 'Savings transfer posted.');
    }

    public function receipt(Request $request, int $savingsTransactionId, SavingsTransactionService $service): View
    {
        return view('savings-transactions.receipt', [
            'transaction' => $service->resolveTransaction($savingsTransactionId, $request->user()),
        ]);
    }
}
