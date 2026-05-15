<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\DrawerSession;
use App\Models\TellerDrawer;
use App\Models\TransactionType;
use App\Services\AuditLogger;
use App\Services\FinancialControlService;
use App\Services\TellerService;
use App\Services\TransactionPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

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

    public function open(Request $request, TellerService $tellers, FinancialControlService $financialControls, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'teller_drawer_id' => ['required', 'exists:teller_drawers,id'],
            'opening_balance' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $drawer = TellerDrawer::query()->findOrFail($data['teller_drawer_id']);
            $financialControls->ensureFinancialDayOpen($request->user(), (int) $drawer->branch_id, today());
            $session = $tellers->openDrawer($drawer, $request->user(), (float) $data['opening_balance']);
            $auditLogger->record('drawer.opened', $session, $session->toArray());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['opening_balance' => $exception->getMessage()])->withInput();
        }

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

    public function close(Request $request, FinancialControlService $financialControls): RedirectResponse
    {
        $data = $request->validate(['closing_balance' => ['required', 'numeric', 'min:0']]);

        try {
            $session = DrawerSession::where('user_id', auth()->id())->where('status', 'open')->latest()->firstOrFail();
            $financialControls->closeDrawer($session, $request->user(), (float) $data['closing_balance']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['closing_balance' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Drawer closed.');
    }
}
