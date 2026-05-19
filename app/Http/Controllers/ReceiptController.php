<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
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

        if ((int) $transaction->user_id === (int) $user->id) {
            return true;
        }

        if ((int) $transaction->branch_id !== (int) $user->branch_id && ! $user->canPerform('manage-organization')) {
            return false;
        }

        return $user->canPerform('approve-reversals')
            || $user->canPerform('view-reports')
            || $user->canPerform('manage-accounts')
            || $user->canPerform('manage-organization');
    }
}
