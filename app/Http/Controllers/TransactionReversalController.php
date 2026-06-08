<?php

namespace App\Http\Controllers;

use App\Models\MakerCheckerRequest;
use App\Models\Transaction;
use App\Services\FinancialControlService;
use App\Support\ApiSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class TransactionReversalController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->canApproveReversals($request), 403);

        $status = in_array($request->string('status')->toString(), ['pending', 'approved', 'rejected'], true)
            ? $request->string('status')->toString()
            : 'pending';
        $search = $request->string('search')->trim()->toString();

        $requests = MakerCheckerRequest::query()
            ->with(['requestedBy', 'checkedBy', 'branch', 'subject.account.client', 'subject.type'])
            ->where('action', 'transaction_reversal')
            ->where('status', $status)
            ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereAny(['reason', 'checker_comments', 'status'], 'like', "%{$search}%")
                        ->orWhereHasMorph('subject', [Transaction::class], function (Builder $query) use ($search): void {
                            $query->whereAny(['reference', 'description', 'status'], 'like', "%{$search}%")
                                ->orWhereHas('account', fn (Builder $query): Builder => $query->whereAny(['account_number', 'account_name'], 'like', "%{$search}%"))
                                ->orWhereHas('account.client', fn (Builder $query): Builder => $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name'], 'like', "%{$search}%"));
                        })
                        ->orWhereHas('requestedBy', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('transaction-reversal-requests.index', [
            'requests' => $requests,
            'status' => $status,
            'search' => $search,
            'statuses' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'],
        ]);
    }

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

        if ($user->isLoanOfficer() || $user->isLoanRecoveryOfficer()) {
            return false;
        }

        if ((int) $transaction->branch_id !== (int) $user->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user)) {
            return false;
        }

        if ($user->isTellerCashier()) {
            return (int) $transaction->user_id === (int) $user->id
                && ($transaction->posted_at ?? $transaction->created_at)->isToday();
        }

        return $user->hasRole('super-admin')
            || $user->hasRole('organization-admin')
            || $user->canPerform('request-reversals')
            || $user->canPerform('approve-reversals')
            || $user->canPerform('override-maker-checker');
    }

    private function canCheck(Request $request, MakerCheckerRequest $makerCheckerRequest): bool
    {
        $user = $request->user();

        if (! $this->canApproveReversals($request)) {
            return false;
        }

        return (int) $makerCheckerRequest->branch_id === (int) $user->branch_id
            || ApiSecurity::hasGlobalBranchAccess($user)
            || $user->canPerform('override-maker-checker');
    }

    private function canApproveReversals(Request $request): bool
    {
        $user = $request->user();

        return $user->canPerform('approve-reversals') || $user->canPerform('override-maker-checker');
    }
}
