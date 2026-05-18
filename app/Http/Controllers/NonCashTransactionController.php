<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNonCashTransactionRequest;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Services\TransactionPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class NonCashTransactionController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(! $request->user()->isMember(), 403);

        $branchId = $this->branchId($request);
        $accountSearch = $request->string('account_search')->trim()->toString();
        $ledgerSearch = $request->string('ledger_search')->trim()->toString();
        $selectedAccountTypeId = $request->integer('account_type_id') ?: null;

        $selectedAccount = $request->integer('account_id')
            ? Account::query()
                ->with(['client', 'accountType', 'branch'])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->whereKey($request->integer('account_id'))
                ->first()
            : null;

        $selectedLedger = $request->integer('chart_account_id')
            ? ChartAccount::query()
                ->with('branch')
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->whereKey($request->integer('chart_account_id'))
                ->first()
            : null;

        return view('non-cash.index', [
            'branches' => Branch::query()
                ->where('is_active', true)
                ->when(! $this->canSeeAllBranches($request), fn (Builder $query): Builder => $query->whereKey($request->user()->branch_id))
                ->orderBy('name')
                ->get(),
            'branchId' => $branchId,
            'accountTypes' => AccountType::query()->where('is_active', true)->orderBy('name')->get(),
            'transactionTypes' => TransactionType::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedAccountTypeId' => $selectedAccountTypeId,
            'selectedAccount' => $selectedAccount,
            'selectedLedger' => $selectedLedger,
            'accountResults' => Account::query()
                ->with(['client', 'accountType', 'branch'])
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->when($selectedAccountTypeId, fn (Builder $query, int $typeId): Builder => $query->where('account_type_id', $typeId))
                ->when($accountSearch !== '', function (Builder $query) use ($accountSearch): void {
                    $query->where(function (Builder $query) use ($accountSearch): void {
                        $query->whereAny(['account_number', 'account_name', 'status'], 'like', "%{$accountSearch}%")
                            ->orWhereHas('client', function (Builder $query) use ($accountSearch): void {
                                $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone', 'alternative_phone'], 'like', "%{$accountSearch}%");
                            });
                    });
                })
                ->when($accountSearch === '', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->orderBy('account_number')
                ->limit(12)
                ->get(),
            'ledgerResults' => ChartAccount::query()
                ->with('branch')
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->where('is_active', true)
                ->where('is_cash_account', false)
                ->when($ledgerSearch !== '', fn (Builder $query): Builder => $query->whereAny(['code', 'name', 'type'], 'like', "%{$ledgerSearch}%"))
                ->when($ledgerSearch === '', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->orderBy('type')
                ->orderBy('name')
                ->limit(12)
                ->get(),
            'receiptTransaction' => $this->receiptTransaction($request),
        ]);
    }

    public function store(StoreNonCashTransactionRequest $request, TransactionPostingService $posting): RedirectResponse
    {
        $data = $request->validated();
        $branchId = $this->branchId($request);

        try {
            $account = Account::query()
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->findOrFail($data['account_id']);
            $chartAccount = ChartAccount::query()
                ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
                ->findOrFail($data['chart_account_id']);
            $type = TransactionType::query()->findOrFail($data['transaction_type_id']);

            $transaction = $posting->postNonCash(
                $account,
                $type,
                $chartAccount,
                (float) $data['amount'],
                $request->user(),
                $data['description'],
                CarbonImmutable::parse($data['transaction_date']),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('non-cash.index')
            ->with('status', 'Non-cash transaction posted.')
            ->with('receipt_transaction_id', $transaction->id);
    }

    private function canSeeAllBranches(Request $request): bool
    {
        return $request->user()->hasRole('super-admin')
            || $request->user()->canPerform('manage-organization')
            || $request->user()->canPerform('audit-system');
    }

    private function branchId(Request $request): ?int
    {
        if ($this->canSeeAllBranches($request)) {
            return $request->integer('branch_id') ?: null;
        }

        return $request->user()->branch_id;
    }

    private function receiptTransaction(Request $request): ?Transaction
    {
        $transactionId = (int) session('receipt_transaction_id');

        if (! $transactionId) {
            return null;
        }

        return Transaction::query()
            ->with(['account.client', 'type', 'user'])
            ->whereKey($transactionId)
            ->when(! $this->canSeeAllBranches($request), fn (Builder $query): Builder => $query->where('branch_id', $request->user()->branch_id))
            ->first();
    }
}
