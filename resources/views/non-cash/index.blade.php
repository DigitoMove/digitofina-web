@extends('layouts.app')

@section('content')
<div class="mx-auto grid max-w-7xl gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-slate-500">Operations</p>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Non-Cash Transactions</h1>
            <p class="mt-1 text-sm text-slate-500">Receive mobile money, merchant-code, bank, and internal payments without changing a teller drawer.</p>
        </div>
        @if(auth()->user()->canPerform('view-reports') && ! auth()->user()->hasRestrictedReportsAccess())
            <a href="{{ route('reports.show', array_filter(['report' => 'non-cash-transactions', 'from' => today()->toDateString(), 'to' => today()->toDateString(), 'branch_id' => $branchId])) }}" class="df-action-secondary">Open Non-Cash Report</a>
        @endif
    </div>

    <section class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
        <div class="font-semibold">Recommended merchant-code setup</div>
        <p class="mt-1">Use a debit-normal <strong>asset</strong> ledger such as "Merchant Code Clearing" or "Mobile Money Clearing", and set its drawer use to <strong>Non-cash only</strong>. Loan principal repayments reduce Loans Receivable; only interest, fees, and penalties are income.</p>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">1. Client account</div>
                    <div class="mt-2 font-semibold text-slate-950">{{ $selectedAccount?->account_number ?? 'No account selected' }}</div>
                    <div class="text-sm text-slate-500">{{ $selectedAccount?->client?->full_name ?? $selectedAccount?->account_name ?? 'Search by client, phone, or account number.' }}</div>
                    @if($selectedAccount)
                        <div class="mt-2 text-xs text-slate-500">{{ $selectedAccount->accountType?->name }} · Balance UGX {{ number_format((float) $selectedAccount->balance, 2) }}</div>
                    @endif
                </div>
                <button type="button" class="df-action-secondary shrink-0" data-non-cash-lookup-open="account">{{ $selectedAccount ? 'Change' : 'Search' }}</button>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">2. Payment / offset ledger</div>
                    <div class="mt-2 font-semibold text-slate-950">{{ $selectedLedger?->name ?? 'No ledger selected' }}</div>
                    <div class="text-sm text-slate-500">{{ $selectedLedger ? $selectedLedger->code.' · '.str($selectedLedger->type)->headline().' · '.str($selectedLedger->normal_balance)->headline().' normal' : 'Search for the non-cash payment-channel ledger.' }}</div>
                    @if($selectedLedger && ($selectedLedger->type !== 'asset' || $selectedLedger->normal_balance !== 'debit'))
                        <div class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Loan repayments require a debit-normal asset ledger.</div>
                    @elseif($selectedLedger && $selectedLedger->drawer_direction !== 'none')
                        <div class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Set Drawer Use to "Non-cash only" to keep this ledger out of teller drawers.</div>
                    @endif
                </div>
                <button type="button" class="df-action-secondary shrink-0" data-non-cash-lookup-open="ledger">{{ $selectedLedger ? 'Change' : 'Search' }}</button>
            </div>
        </section>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">3. Post transaction</div>
            <h2 class="mt-1 text-base font-semibold text-slate-900">Record Non-Cash Payment</h2>
        </div>
        <form method="POST" action="{{ route('non-cash.store') }}" class="mt-4 grid gap-4 lg:grid-cols-4" data-confirm-title="Review non-cash transaction" data-confirm-message="Please confirm these details before posting. This helps avoid reversal requests." data-confirm-label="Post non-cash transaction" data-confirm-tone="neutral">
            @csrf
            <input type="hidden" name="account_id" value="{{ old('account_id', $selectedAccount?->id) }}">
            <input type="hidden" name="chart_account_id" value="{{ old('chart_account_id', $selectedLedger?->id) }}">
            <input type="hidden" data-confirm-summary-label="Account" data-confirm-summary-value="{{ $selectedAccount ? $selectedAccount->account_number.' · '.($selectedAccount->client?->full_name ?? $selectedAccount->account_name) : 'None selected' }}">
            <input type="hidden" data-confirm-summary-label="Offset ledger" data-confirm-summary-value="{{ $selectedLedger ? $selectedLedger->code.' · '.$selectedLedger->name : 'None selected' }}">

            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Transaction type</span>
                <select name="transaction_type_id" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" data-confirm-summary-label="Transaction type">
                    <option value="">Choose type</option>
                    @foreach($transactionTypes as $type)
                        <option value="{{ $type->id }}" @selected((int) old('transaction_type_id') === $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Transaction date</span>
                <input type="date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" data-confirm-summary-label="Date">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Amount</span>
                <input type="number" name="amount" value="{{ old('amount') }}" min="100" step="100" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="100" data-confirm-summary-label="Amount">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Reason / narration</span>
                <input name="description" value="{{ old('description') }}" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="Merchant code repayment, bank transfer..." data-confirm-summary-label="Reason">
            </label>
            <div class="lg:col-span-4">
                <button class="rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300" @disabled(! $selectedAccount || ! $selectedLedger)>Post Non-Cash Transaction</button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-5">
            <div>
                <h2 class="font-semibold text-slate-950">Recent Non-Cash History</h2>
                <p class="text-sm text-slate-500">Who paid, when it was posted, the payment ledger used, and the amount.</p>
            </div>
            <form method="GET" action="{{ route('non-cash.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="account_id" value="{{ $selectedAccount?->id }}">
                <input type="hidden" name="chart_account_id" value="{{ $selectedLedger?->id }}">
                <input name="history_search" value="{{ $historySearch }}" class="min-w-64 rounded border-slate-300 text-sm" placeholder="Client, account, reference, or ledger">
                <input name="history_from" type="date" value="{{ $historyFrom }}" class="rounded border-slate-300 text-sm" aria-label="History from date">
                <input name="history_to" type="date" value="{{ $historyTo }}" class="rounded border-slate-300 text-sm" aria-label="History to date">
                <button class="df-action-secondary">Filter</button>
            </form>
        </div>
        <div class="overflow-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Date & Time</th>
                        <th class="px-4 py-3">Paid For</th>
                        <th class="px-4 py-3">Payment Ledger</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Posted By</th>
                        <th class="px-4 py-3">Reference</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $transaction)
                        @php
                            $paymentLedger = $transaction->loanRepayment
                                ? $transaction->journalEntries->firstWhere('entry_type', 'debit')?->chartAccount
                                : $transaction->journalEntries->first(fn ($entry) => str($entry->journal_number)->startsWith('NC'))?->chartAccount;
                        @endphp
                        <tr class="border-t border-slate-200 align-top">
                            <td class="whitespace-nowrap px-4 py-3">{{ $transaction->posted_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-950">{{ $transaction->account?->client?->full_name ?? $transaction->account?->account_name ?? 'N/A' }}</div>
                                <div class="text-xs text-slate-500">{{ $transaction->account?->account_number }}{{ $transaction->loanRepayment?->loan ? ' · '.$transaction->loanRepayment->loan->loan_number : '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">{{ $paymentLedger?->name ?? 'Unmapped' }}</div>
                                <div class="text-xs text-slate-500">{{ $paymentLedger?->code }}</div>
                            </td>
                            <td class="px-4 py-3">{{ $transaction->type?->name ?? 'N/A' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-950">UGX {{ number_format((float) $transaction->amount, 2) }}</td>
                            <td class="px-4 py-3">{{ $transaction->user?->name ?? 'System' }}</td>
                            <td class="px-4 py-3"><a href="{{ route('receipts.show', $transaction) }}" class="font-semibold text-brand-700 hover:underline">{{ $transaction->reference }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No non-cash transactions matched these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="fixed inset-0 z-[90] {{ request('account_search') ? 'grid' : 'hidden' }} place-items-center bg-slate-950/60 p-4" data-non-cash-lookup-modal="account" data-open="{{ request('account_search') ? '1' : '0' }}">
    <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div><h3 class="text-lg font-semibold text-slate-950">Search Client Account</h3><p class="text-sm text-slate-500">Search and select without navigating a wide table.</p></div>
            <button type="button" class="df-action-secondary" data-non-cash-lookup-close>Close</button>
        </div>
        <form method="GET" action="{{ route('non-cash.index') }}" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-5 md:grid-cols-[220px_1fr_auto]">
            <input type="hidden" name="chart_account_id" value="{{ $selectedLedger?->id }}">
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <select name="account_type_id">
                <option value="">All account types</option>
                @foreach($accountTypes as $type)
                    <option value="{{ $type->id }}" @selected($selectedAccountTypeId === $type->id)>{{ $type->name }}</option>
                @endforeach
            </select>
            <input name="account_search" value="{{ request('account_search') }}" placeholder="Name, phone, client number, or account number" autofocus>
            <button class="df-action-primary justify-center">Search</button>
        </form>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div class="divide-y divide-slate-200 rounded border border-slate-200">
                @forelse($accountResults as $account)
                    <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto]">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $account->account_number }} · {{ $account->client?->full_name ?? $account->account_name }}</div>
                            <div class="text-sm text-slate-500">{{ $account->accountType?->name }} · {{ $account->branch?->name }} · Balance UGX {{ number_format((float) $account->balance, 2) }}</div>
                        </div>
                        <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except(['account_search', 'ledger_search']), ['account_id' => $account->id, 'chart_account_id' => $selectedLedger?->id]))) }}" class="df-action-primary self-start">Select</a>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-slate-500">Type a keyword to find an account.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="fixed inset-0 z-[90] {{ request('ledger_search') ? 'grid' : 'hidden' }} place-items-center bg-slate-950/60 p-4" data-non-cash-lookup-modal="ledger" data-open="{{ request('ledger_search') ? '1' : '0' }}">
    <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div><h3 class="text-lg font-semibold text-slate-950">Search Payment Ledger</h3><p class="text-sm text-slate-500">For merchant-code loan payments, choose a debit-normal asset ledger marked non-cash only.</p></div>
            <button type="button" class="df-action-secondary" data-non-cash-lookup-close>Close</button>
        </div>
        <form method="GET" action="{{ route('non-cash.index') }}" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-5 md:grid-cols-[1fr_auto]">
            <input type="hidden" name="account_id" value="{{ $selectedAccount?->id }}">
            <input type="hidden" name="account_type_id" value="{{ $selectedAccountTypeId }}">
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <input name="ledger_search" value="{{ request('ledger_search') }}" placeholder="Ledger name, code, or type" autofocus>
            <button class="df-action-primary justify-center">Search</button>
        </form>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div class="divide-y divide-slate-200 rounded border border-slate-200">
                @forelse($ledgerResults as $ledger)
                    <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto]">
                        <div>
                            <div class="flex flex-wrap items-center gap-2 font-semibold text-slate-950">
                                <span>{{ $ledger->code }} · {{ $ledger->name }}</span>
                                @if($ledger->type === 'asset' && $ledger->normal_balance === 'debit' && $ledger->drawer_direction === 'none')
                                    <span class="rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-700">Recommended for payment channels</span>
                                @endif
                            </div>
                            <div class="text-sm text-slate-500">{{ str($ledger->type)->headline() }} · {{ str($ledger->normal_balance)->headline() }} normal · {{ $ledger->drawer_direction === 'none' ? 'Non-cash only' : 'Available to drawer' }} · {{ $ledger->branch?->name }}</div>
                        </div>
                        <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except(['account_search', 'ledger_search']), ['account_id' => $selectedAccount?->id, 'chart_account_id' => $ledger->id]))) }}" class="df-action-primary self-start">Select</a>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-slate-500">Type a keyword to find a ledger account.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<x-transaction-receipt-modal :transaction="$receiptTransaction" :close-url="route('non-cash.index')" />
@endsection
