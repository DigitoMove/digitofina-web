<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Services\AccountNumberGenerator;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        return view('accounts.index', [
            'accounts' => Account::with(['client', 'accountType', 'branch'])->latest()->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        return view('accounts.create', [
            'clients' => Client::orderBy('first_name')->get(),
            'types' => AccountType::where('is_active', true)->get(),
            'branches' => Branch::where('is_active', true)->get(),
            'selectedClient' => $request->integer('client_id'),
        ]);
    }

    public function store(Request $request, AccountNumberGenerator $numbers, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'account_type_id' => ['required', 'exists:account_types,id'],
            'account_name' => ['required', 'string', 'max:191'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $type = AccountType::findOrFail($data['account_type_id']);

        if ($type->category === 'savings') {
            $client = Client::query()->with(['accounts.accountType', 'savingsAccounts'])->findOrFail($data['client_id']);

            if ($client->savingsAccounts->isNotEmpty() || $client->accounts->first(fn ($account) => $account->accountType?->category === 'savings')) {
                throw ValidationException::withMessages(['account_type_id' => 'This client already has a savings account. Each client can only have one savings account.']);
            }
        }

        $account = Account::create($data + [
            'organization_id' => auth()->user()->organization_id,
            'account_number' => $numbers->accountNumber($branch, $type),
            'opened_at' => now()->toDateString(),
        ]);

        $auditLogger->record('account.created', $account, $account->toArray());

        return redirect()->route('accounts.show', $account)->with('status', 'Account opened.');
    }

    public function show(Account $account): View
    {
        return view('accounts.show', [
            'account' => $account->load(['client', 'accountType', 'transactions.type']),
        ]);
    }
}
