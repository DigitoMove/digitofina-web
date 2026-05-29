<?php

namespace App\Http\Controllers;

use App\Services\TransactionDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TransactionDeletionController extends Controller
{
    public function destroyByReference(Request $request, TransactionDeletionService $deletions): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:255'],
        ]);

        try {
            $deleted = $deletions->deleteByReference(trim($data['reference']), $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['reference' => $exception->getMessage()]);
        }

        $direction = $deleted['drawer_direction'] === 'cash_in' ? 'restored to' : 'removed from';

        return back()->with('status', 'Transaction '.$deleted['reference'].' deleted and UGX '.number_format($deleted['amount'], 2).' '.$direction.' the responsible drawer.');
    }
}
