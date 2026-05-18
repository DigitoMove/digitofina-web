<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsAccountRequest;
use App\Models\Branch;
use App\Models\Client;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\SavingsAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavingsAccountController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = SavingsAccount::query()
            ->with(['client', 'branch', 'product'])
            ->when($request->search, function ($query, string $search) {
                $query->where('account_number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($query) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone'], 'like', "%{$search}%"));
            })
            ->when($request->branch_id, fn ($query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($request->savings_product_id, fn ($query, string $productId) => $query->where('savings_product_id', $productId))
            ->when($request->status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('savings-accounts.index', [
            'accounts' => $accounts,
            'branches' => Branch::orderBy('name')->get(),
            'products' => SavingsProduct::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $selectedClientId = $request->integer('client_id');

        if ($selectedClientId) {
            $selectedClient = Client::query()->with(['savingsAccounts', 'accounts.accountType'])->findOrFail($selectedClientId);
            $existingSavingsAccount = $selectedClient->savingsAccounts->first();

            if ($existingSavingsAccount) {
                return redirect()->route('savings-accounts.show', $existingSavingsAccount)->with('status', 'This client already has a savings account.');
            }

            if ($selectedClient->accounts->first(fn ($account) => $account->accountType?->category === 'savings')) {
                return redirect()->route('clients.show', $selectedClient)->with('status', 'This client already has a savings account.');
            }
        }

        return view('savings-accounts.create', [
            'account' => new SavingsAccount(['status' => 'active', 'opening_date' => today()]),
            'clients' => Client::where('status', 'active')
                ->whereDoesntHave('savingsAccounts')
                ->whereDoesntHave('accounts', fn ($query) => $query->whereHas('accountType', fn ($query) => $query->where('category', 'savings')))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'products' => SavingsProduct::where('status', 'active')->orderBy('name')->get(),
            'selectedClient' => $selectedClientId,
        ]);
    }

    public function store(StoreSavingsAccountRequest $request, SavingsAccountService $accounts): RedirectResponse
    {
        $account = $accounts->open($request->validated(), $request->user());

        return redirect()->route('savings-accounts.show', $account)->with('status', 'Savings account opened.');
    }

    public function generate(Request $request, Client $client, SavingsAccountService $accounts): RedirectResponse
    {
        $account = $accounts->generateForClient($client->load(['branch', 'savingsAccounts', 'accounts.accountType']), $request->user());

        return redirect()->route('savings-accounts.show', $account)->with('status', 'Savings account generated.');
    }

    public function show(SavingsAccount $savingsAccount): View
    {
        return view('savings-accounts.show', [
            'account' => $savingsAccount->load(['client', 'branch', 'product', 'transactions.teller']),
            'recentTransactions' => $savingsAccount->transactions()->latest()->paginate(15),
        ]);
    }

    public function statement(Request $request, SavingsAccount $savingsAccount): View
    {
        $transactions = $savingsAccount->transactions()
            ->when($request->date_from, fn ($query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($request->date_to, fn ($query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->latest('transaction_date')
            ->paginate(30)
            ->withQueryString();

        return view('savings-accounts.statement', ['account' => $savingsAccount->load(['client', 'product', 'branch']), 'transactions' => $transactions]);
    }
}
