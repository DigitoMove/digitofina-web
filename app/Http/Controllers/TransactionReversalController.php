<?php

namespace App\Http\Controllers;

use App\Models\MakerCheckerRequest;
use App\Models\Transaction;
use App\Services\FinancialControlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TransactionReversalController extends Controller
{
    public function store(Request $request, Transaction $transaction, FinancialControlService $controls): RedirectResponse
    {
        if (! $this->canRequest($request, $transaction)) {
            return back()->with('warning', 'You are not allowed to request a reversal for this transaction.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($transaction->status !== 'posted' || $transaction->reversed_by !== null) {
            return back()->with('warning', 'Only posted transactions that have not been reversed can be requested for reversal.');
        }

        $hasPendingRequest = $transaction->reversalRequests()->where('status', 'pending')->exists();

        if ($hasPendingRequest) {
            return back()->with('warning', 'A reversal request is already pending for this transaction.');
        }

        try {
            $controls->requestAction(
                maker: $request->user(),
                action: 'transaction_reversal',
                subject: $transaction,
                payload: ['reason' => $data['reason']],
                amount: (float) $transaction->amount,
                requiredPermission: 'approve-reversals',
                reason: $data['reason'],
                oldValues: $transaction->only(['status', 'amount', 'balance_after', 'reference']),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('status', 'Reversal request submitted for admin or supervisor approval.');
    }

    public function approve(Request $request, MakerCheckerRequest $makerCheckerRequest, FinancialControlService $controls): RedirectResponse
    {
        if ($makerCheckerRequest->action !== 'transaction_reversal') {
            return back()->with('warning', 'This approval request is not a transaction reversal.');
        }

        if (! $this->canCheck($request, $makerCheckerRequest)) {
            return back()->with('warning', 'You are not allowed to approve this reversal request.');
        }

        $data = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $controls->approveMakerChecker($makerCheckerRequest, $request->user(), $data['comments'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('status', 'Transaction reversal approved and posted.');
    }

    public function reject(Request $request, MakerCheckerRequest $makerCheckerRequest, FinancialControlService $controls): RedirectResponse
    {
        if ($makerCheckerRequest->action !== 'transaction_reversal') {
            return back()->with('warning', 'This approval request is not a transaction reversal.');
        }

        if (! $this->canCheck($request, $makerCheckerRequest)) {
            return back()->with('warning', 'You are not allowed to reject this reversal request.');
        }

        $data = $request->validate([
            'comments' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $controls->rejectMakerChecker($makerCheckerRequest, $request->user(), $data['comments']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('status', 'Transaction reversal rejected.');
    }

    private function canRequest(Request $request, Transaction $transaction): bool
    {
        $user = $request->user();

        if ((int) $transaction->branch_id !== (int) $user->branch_id && ! $user->canPerform('manage-organization')) {
            return false;
        }

        return $user->canPerform('post-transactions')
            || $user->canPerform('approve-reversals')
            || (int) $transaction->user_id === (int) $user->id;
    }

    private function canCheck(Request $request, MakerCheckerRequest $makerCheckerRequest): bool
    {
        $user = $request->user();

        if (! ($user->canPerform('approve-reversals') || $user->canPerform('override-maker-checker'))) {
            return false;
        }

        return (int) $makerCheckerRequest->branch_id === (int) $user->branch_id
            || $user->canPerform('manage-organization')
            || $user->canPerform('override-maker-checker');
    }
}
