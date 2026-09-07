<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\ApiSecurity;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function show(Request $request, Transaction $transaction): View
    {
        abort_unless($this->canView($request, $transaction), 403);

        return view('receipts.show', [
            'transaction' => $transaction->load([
                'account.client',
                'journalEntries.chartAccount',
                'type',
                'user',
                'reversal',
                'reversalRequests' => fn ($query) => $query->with(['requestedBy', 'checkedBy'])->latest(),
            ]),
        ]);
    }

    private function canView(Request $request, Transaction $transaction): bool
    {
        $user = $request->user();
        $transaction->loadMissing('account.client');

        if ($user->isLoanOfficer() || $user->isLoanRecoveryOfficer()) {
            return false;
        }

        if ($user->isTellerCashier()) {
            if (! ($transaction->posted_at ?? $transaction->created_at)->isToday()) {
                return false;
            }

            if ((int) $transaction->user_id === (int) $user->id) {
                return true;
            }

            $session = $user->drawerSessions()->where('status', 'open')->latest()->first();

            return $session && ((int) $transaction->drawer_session_id === (int) $session->id
                || $transaction->drawerMovements()->where('drawer_session_id', $session->id)->exists());
        }

        if ((int) $transaction->user_id === (int) $user->id) {
            return true;
        }

        if ((int) $transaction->branch_id !== (int) $user->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user)) {
            return false;
        }

        return $user->canPerform('approve-reversals')
            || $user->canPerform('view-reports')
            || $user->canPerform('manage-accounts')
            || ($transaction->account?->client && $user->can('view', $transaction->account->client))
            || ApiSecurity::hasGlobalBranchAccess($user);
    }
}
