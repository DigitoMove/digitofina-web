<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsAccountRequest;
use App\Models\Branch;
use App\Models\Client;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\AuditLogger;
use App\Services\SavingsTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        return view('savings-accounts.create', [
            'account' => new SavingsAccount(['status' => 'active', 'opening_date' => today()]),
            'clients' => Client::where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'products' => SavingsProduct::where('status', 'active')->orderBy('name')->get(),
            'selectedClient' => $request->integer('client_id'),
        ]);
    }

    public function store(StoreSavingsAccountRequest $request, AuditLogger $auditLogger, SavingsTransactionService $transactions): RedirectResponse
    {
        $data = $request->validated();
        $client = Client::findOrFail($data['client_id']);
        $product = SavingsProduct::findOrFail($data['savings_product_id']);
        $openingDeposit = (float) ($data['opening_deposit'] ?? 0);

        if ($client->status !== 'active') {
            throw ValidationException::withMessages(['client_id' => 'A client must be active before opening a savings account.']);
        }

        if ($openingDeposit > 0 && $openingDeposit < (float) $product->minimum_opening_balance) {
            throw ValidationException::withMessages(['opening_deposit' => 'Opening deposit is below the product minimum opening balance.']);
        }

        $account = DB::transaction(function () use ($data, $product, $openingDeposit, $auditLogger, $transactions): SavingsAccount {
            $account = SavingsAccount::create([
                'organization_id' => auth()->user()->organization_id,
                'branch_id' => $data['branch_id'],
                'client_id' => $data['client_id'],
                'savings_product_id' => $product->id,
                'created_by' => auth()->id(),
                'account_number' => $this->accountNumber($data['branch_id'], $product),
                'opening_date' => $data['opening_date'],
                'current_balance' => 0,
                'available_balance' => 0,
                'status' => $data['status'],
            ]);

            if ($openingDeposit > 0) {
                if ($account->status !== 'active') {
                    $account->update(['status' => 'active']);
                }

                $transactions->deposit($account, $openingDeposit, auth()->user(), 'cash', 'Opening deposit');
                $account->refresh();
            }

            $auditLogger->record('savings_account.created', $account, $account->toArray());

            return $account;
        });

        return redirect()->route('savings-accounts.show', $account)->with('status', 'Savings account opened.');
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

    private function accountNumber(int|string $branchId, SavingsProduct $product): string
    {
        $branch = Branch::findOrFail($branchId);
        $sequence = SavingsAccount::query()->where('branch_id', $branch->id)->where('savings_product_id', $product->id)->count() + 1;

        return $branch->code.'-'.$product->account_number_prefix.'-'.Str::padLeft((string) $sequence, 7, '0');
    }
}
