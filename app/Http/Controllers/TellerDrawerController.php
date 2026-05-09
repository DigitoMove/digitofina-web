<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\DrawerSession;
use App\Models\TellerDrawer;
use App\Models\TransactionType;
use App\Services\AuditLogger;
use App\Services\TransactionPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TellerDrawerController extends Controller
{
    public function index(Request $request): View
    {
        $session = DrawerSession::with('drawer')->where('user_id', auth()->id())->where('status', 'open')->latest()->first();
        $account = $request->account_number ? Account::with(['client', 'accountType'])->where('account_number', $request->account_number)->first() : null;

        return view('teller.index', [
            'session' => $session,
            'drawer' => $session?->drawer,
            'drawers' => TellerDrawer::where('assigned_user_id', auth()->id())->orWhereNull('assigned_user_id')->get(),
            'accountTypes' => AccountType::where('is_active', true)->get(),
            'transactionTypes' => TransactionType::where('is_active', true)->get(),
            'account' => $account,
            'recentTransactions' => $session ? $session->drawer->sessions()->with('movements')->latest()->limit(5)->get() : collect(),
        ]);
    }

    public function open(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'teller_drawer_id' => ['required', 'exists:teller_drawers,id'],
            'opening_balance' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $auditLogger) {
            $drawer = TellerDrawer::whereKey($data['teller_drawer_id'])->lockForUpdate()->firstOrFail();
            $drawer->update(['status' => 'open', 'current_balance' => $data['opening_balance'], 'assigned_user_id' => auth()->id()]);

            $session = DrawerSession::create([
                'teller_drawer_id' => $drawer->id,
                'user_id' => auth()->id(),
                'opening_balance' => $data['opening_balance'],
                'status' => 'open',
            ]);

            $auditLogger->record('drawer.opened', $session, $session->toArray());
        });

        return back()->with('status', 'Drawer opened.');
    }

    public function post(Request $request, TransactionPostingService $posting): RedirectResponse
    {
        $data = $request->validate([
            'account_number' => ['required', 'exists:accounts,account_number'],
            'transaction_type_id' => ['required', 'exists:transaction_types,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'cheque_number' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
        ]);

        $session = DrawerSession::where('user_id', auth()->id())->where('status', 'open')->latest()->firstOrFail();
        $account = Account::where('account_number', $data['account_number'])->firstOrFail();
        $type = TransactionType::findOrFail($data['transaction_type_id']);
        $transaction = $posting->postCash($account, $session, $type, (float) $data['amount'], auth()->user(), $data['description'] ?? null, $data['cheque_number'] ?? null);

        return redirect()->route('receipts.show', $transaction)->with('status', 'Transaction posted.');
    }

    public function close(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate(['closing_balance' => ['required', 'numeric', 'min:0']]);

        DB::transaction(function () use ($data, $auditLogger) {
            $session = DrawerSession::where('user_id', auth()->id())->where('status', 'open')->lockForUpdate()->latest()->firstOrFail();
            $drawer = $session->drawer()->lockForUpdate()->firstOrFail();
            $expected = (float) $drawer->current_balance;

            $session->update([
                'closing_balance' => $data['closing_balance'],
                'expected_balance' => $expected,
                'variance' => (float) $data['closing_balance'] - $expected,
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            $drawer->update(['status' => 'closed', 'current_balance' => $data['closing_balance']]);
            $auditLogger->record('drawer.closed', $session, $session->toArray());
        });

        return back()->with('status', 'Drawer closed.');
    }
}
