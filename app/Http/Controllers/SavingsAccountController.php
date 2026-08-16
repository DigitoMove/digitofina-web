<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsAccountRequest;
use App\Models\Branch;
use App\Models\Client;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\ClientService;
use App\Services\SavingsAccountService;
use App\Services\SavingsTransactionService;
use App\Support\ApiSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavingsAccountController extends Controller
{
    public function index(Request $request, SavingsTransactionService $transactions): View
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('post-transactions'), 403);

        $canTransactAcrossBranches = $request->user()->canPerform('transact-savings-across-branches');
        $accountsQuery = $request->user()->canPerform('post-transactions')
            ? $transactions->transactionAccountQuery($request->user())
            : SavingsAccount::query();
        $branchesQuery = $canTransactAcrossBranches
            ? Branch::query()->withoutGlobalScope('tenant')
                ->when($request->user()->organization_id, fn ($query, int $organizationId) => $query->where('organization_id', $organizationId))
            : Branch::query();

        $accounts = $accountsQuery
            ->with(['client', 'branch', 'product'])
            ->when($request->search, function ($query, string $search) {
                $query->where(function ($query) use ($search): void {
                    $query->where('account_number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($query) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone'], 'like', "%{$search}%"));
                });
            })
            ->when($request->branch_id, fn ($query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($request->savings_product_id, fn ($query, string $productId) => $query->where('savings_product_id', $productId))
            ->when($request->status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('savings-accounts.index', [
            'accounts' => $accounts,
            'branches' => $branchesQuery
                ->when(! $canTransactAcrossBranches && ! ApiSecurity::hasGlobalBranchAccess($request->user()), fn ($query) => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'products' => SavingsProduct::orderBy('name')->get(),
            'canManageAccounts' => $request->user()->canPerform('manage-accounts'),
            'canPostTransactions' => $request->user()->canPerform('post-transactions'),
        ]);
    }

    public function create(Request $request): View
    {
        $selectedClientId = $request->integer('client_id');

        if ($selectedClientId) {
            $selectedClient = Client::query()->with(['savingsAccounts', 'accounts.accountType'])->findOrFail($selectedClientId);
        }

        return view('savings-accounts.create', [
            'account' => new SavingsAccount(['status' => 'active', 'opening_date' => today()]),
            'clients' => Client::where('status', 'active')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
            'branches' => Branch::where('is_active', true)
                ->when(! ApiSecurity::hasGlobalBranchAccess($request->user()), fn ($query) => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'products' => SavingsProduct::where('status', 'active')->orderBy('name')->get(),
            'selectedClient' => $selectedClientId,
            'selectedBranch' => $selectedClient->branch_id ?? null,
        ]);
    }

    public function store(StoreSavingsAccountRequest $request, SavingsAccountService $accounts): RedirectResponse
    {
        $account = $accounts->open($request->validated(), $request->user());

        return redirect()->route('savings-accounts.show', $account)->with('status', 'Savings account opened.');
    }

    public function generate(Request $request, Client $client, ClientService $clients): RedirectResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-loans'), 403);

        $clients->ensureBasicSavingsAccount($client, $request->user());

        return redirect()->route('clients.savings-accounts', $client)->with('status', 'Basic savings account generated.');
    }

    public function show(SavingsAccount $savingsAccount): View
    {
        return view('savings-accounts.show', [
            'account' => $savingsAccount->load(['client', 'branch', 'product', 'transactions.teller']),
            'recentTransactions' => $savingsAccount->transactions()
                ->orderByDesc('transaction_date')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(15),
        ]);
    }

    public function destroy(Request $request, SavingsAccount $savingsAccount): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin') || $request->user()->canPerform('manage-accounts'), 403);

        $client = $savingsAccount->client;
        $savingsAccount->update(['status' => 'removed']);
        $savingsAccount->delete();

        return redirect()->route('clients.savings-accounts', $client)->with('status', 'Savings product package removed. The client basic savings account was not deleted.');
    }

    public function statement(Request $request, SavingsAccount $savingsAccount): View
    {
        $transactions = $savingsAccount->transactions()
            ->when($request->date_from, fn ($query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($request->date_to, fn ($query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('savings-accounts.statement', ['account' => $savingsAccount->load(['client', 'product', 'branch']), 'transactions' => $transactions]);
    }
}
