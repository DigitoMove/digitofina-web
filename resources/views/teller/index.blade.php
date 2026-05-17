@extends('layouts.app')

@section('content')
@php
    $statusClass = $session ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200';
    $tiles = [
        ['Drawer Balance', number_format($drawer?->current_balance ?? 0, 2).' UGX', 'bg-brand-600', 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10'],
        ['Cash In Today', number_format($cashIn, 2).' UGX', 'bg-emerald-600', 'M12 5v14m0-14 5 5m-5-5-5 5'],
        ['Cash Out Today', number_format($cashOut, 2).' UGX', 'bg-amber-500', 'M12 19V5m0 14 5-5m-5 5-5-5'],
        ['Due Collections', number_format($dueToday->count()), 'bg-red-600', 'M12 8v5l3 3 M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0z'],
    ];
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Teller / Cashier Dashboard</h1>
        <p class="df-muted">Drawer operations, account lookup, postings, and today’s collections.</p>
    </div>
    <div class="rounded border px-4 py-3 text-sm {{ $statusClass }}">
        <span class="font-semibold">{{ $session ? 'Open drawer' : 'No open drawer' }}</span>
        <span class="ml-2">{{ $drawer?->name ?? 'Open a drawer to start' }}</span>
    </div>
</div>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach($tiles as [$label, $value, $color, $icon])
        <div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
            <div class="{{ $color }} relative p-5 text-white">
                <div class="text-3xl font-semibold">{{ $value }}</div>
                <div class="mt-2 text-sm font-semibold">{{ $label }}</div>
                <svg class="absolute right-5 top-5 h-16 w-16 text-white/20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
            </div>
            <div class="bg-slate-900/10 px-5 py-2 text-right text-xs font-semibold text-slate-600">Today</div>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
    <section class="df-panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Drawer Opening</h2>
                <p class="df-muted">Open a till, or continue with the active drawer.</p>
            </div>
            <span class="df-badge {{ $session ? 'border-emerald-500/20 bg-emerald-50 text-emerald-700' : 'border-amber-400/20 bg-amber-50 text-amber-700' }}">{{ $session ? 'Ready' : 'Required' }}</span>
        </div>
        @unless($session)
            <form method="POST" action="{{ route('teller.open') }}" class="mt-5 grid gap-3">
                @csrf
                <label class="text-sm font-semibold text-slate-700">Drawer
                    <select name="teller_drawer_id" class="mt-1">
                        @foreach($drawers as $openDrawer)
                            <option value="{{ $openDrawer->id }}" @selected((int) old('teller_drawer_id') === $openDrawer->id)>{{ $openDrawer->code }} {{ $openDrawer->name }}</option>
                        @endforeach
                    </select>
                </label>
                @if($canOpenForTellers)
                    <label class="text-sm font-semibold text-slate-700">Teller / Cashier
                        <select name="teller_user_id" class="mt-1">
                            <option value="">Select teller/cashier</option>
                            @foreach($tellers as $teller)
                                <option value="{{ $teller->id }}" @selected((int) old('teller_user_id') === $teller->id)>{{ $teller->name }} · {{ $teller->role?->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="text-sm font-semibold text-slate-700">Opening Balance
                    <input name="opening_balance" type="number" min="0" step="100" value="{{ old('opening_balance') }}" class="mt-1" placeholder="Opening balance">
                </label>
                <button class="df-action-primary">Open Drawer</button>
            </form>
        @else
            <div class="mt-5 grid gap-3 text-sm">
                <div class="df-soft-panel flex items-center justify-between gap-3 p-4">
                    <span>Opening balance</span>
                    <span class="font-semibold text-slate-950">UGX {{ number_format($session->opening_balance, 2) }}</span>
                </div>
                <div class="df-soft-panel flex items-center justify-between gap-3 p-4">
                    <span>Expected balance</span>
                    <span class="font-semibold text-slate-950">UGX {{ number_format($expectedBalance, 2) }}</span>
                </div>
                <div class="df-soft-panel flex items-center justify-between gap-3 p-4">
                    <span>Net movement</span>
                    <span class="font-semibold {{ $netCashMovement >= 0 ? 'text-emerald-700' : 'text-red-700' }}">UGX {{ number_format($netCashMovement, 2) }}</span>
                </div>
            </div>
        @endunless
    </section>

    <section class="df-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Account Lookup</h2>
                <p class="df-muted">Find savings, loan, or GL posting targets.</p>
            </div>
            <a href="{{ route('due-repayments.index') }}" class="df-action-secondary">Due Repayments</a>
        </div>
        <form method="GET" action="{{ route('teller.index') }}" class="mt-4 grid gap-3 md:grid-cols-[180px_1fr_1fr_auto]">
            <select name="account_type_id">
                <option value="">Account Type</option>
                @foreach($accountTypes as $type)
                    <option value="{{ $type->id }}" @selected((int) request('account_type_id') === $type->id)>{{ $type->name }}</option>
                @endforeach
            </select>
            <input name="account_number" value="{{ request('account_number') }}" placeholder="Account number">
            <input name="gl_search" value="{{ request('gl_search') }}" placeholder="Search GL: welfare, transport, travel">
            <button class="df-action-primary">Search</button>
        </form>
        <div class="mt-4 rounded border border-slate-200 bg-slate-50 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Selected account</div>
                    <div class="mt-1 font-semibold text-slate-950">{{ $account?->account_name ?? 'Search an active account' }}</div>
                    @if($account)
                        <div class="text-sm text-slate-500">{{ $account->account_number }} · {{ $account->accountType?->name }}</div>
                    @endif
                </div>
                @if($account)
                    <div class="text-right">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $account->accountType?->category === 'loan' ? 'Outstanding' : 'Available' }}</div>
                        <div class="text-xl font-semibold text-brand-800">UGX {{ number_format($account->balance, 2) }}</div>
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
    <section class="df-panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Post Transaction</h2>
                <p class="df-muted">Post cash to a client account or a branch general ledger account.</p>
            </div>
            <span class="df-badge">UGX 100 minimum denomination</span>
        </div>
        <form method="POST" action="{{ route('teller.post') }}" class="mt-4 grid gap-4 md:grid-cols-2">
            @csrf
            @if($errors->any())
                <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700 md:col-span-2">{{ $errors->first() }}</div>
            @endif
            <label class="text-sm font-semibold text-slate-700">Posting Account
                <select name="account_scope" class="mt-1">
                    <option value="client_account" @selected(old('account_scope', 'client_account') === 'client_account')>Savings / Loan / Other Client Account</option>
                    <option value="general_ledger" @selected(old('account_scope') === 'general_ledger')>General Ledger Account</option>
                </select>
            </label>
            <input type="hidden" name="account_number" value="{{ $account?->account_number ?? request('account_number') }}">
            <label class="text-sm font-semibold text-slate-700">Transaction Type
                <select name="transaction_type_id" class="mt-1">
                    @foreach($transactionTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">General Ledger Account
                <select name="chart_account_id" class="mt-1">
                    <option value="">Select branch GL account</option>
                    @foreach($chartAccounts->groupBy('type') as $type => $accounts)
                        <optgroup label="{{ str($type)->headline() }}">
                            @foreach($accounts as $chartAccount)
                                <option value="{{ $chartAccount->id }}" @selected((int) old('chart_account_id') === $chartAccount->id)>{{ $chartAccount->code }} · {{ $chartAccount->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">GL Side
                <select name="gl_entry_type" class="mt-1">
                    <option value="debit" @selected(old('gl_entry_type') === 'debit')>Debit selected GL account</option>
                    <option value="credit" @selected(old('gl_entry_type') === 'credit')>Credit selected GL account</option>
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Cheque No
                <input name="cheque_number" class="mt-1">
            </label>
            <label class="text-sm font-semibold text-slate-700">Amount
                <input name="amount" type="number" min="100" step="100" class="mt-1">
            </label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">Transaction Description
                <textarea name="description" rows="3" class="mt-1"></textarea>
            </label>
            <div class="flex flex-wrap gap-2 md:col-span-2">
                <button @disabled(!$session) class="df-action-primary disabled:pointer-events-none disabled:bg-slate-400">Post Transaction</button>
                <a href="{{ route('reports.show', 'cash-balances') }}" class="df-action-secondary">Cash Balance Report</a>
            </div>
        </form>
    </section>

    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">{{ today()->format('d-m-Y') }} Installments Due</h2>
            <p class="df-muted">Collections expected at this branch today.</p>
        </div>
        <div class="max-h-[31rem] overflow-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-800 text-white">
                    <tr><th class="p-3">Name</th><th>Telephone</th><th>Account</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    @forelse($dueToday as $schedule)
                        <tr class="border-t border-slate-200">
                            <td class="p-3 font-medium text-slate-950">{{ $schedule->loan->client->full_name }}</td>
                            <td>{{ $schedule->loan->client->phone }}</td>
                            <td>{{ $schedule->loan->loanAccount?->account_number ?? $schedule->loan->loan_number }}</td>
                            <td class="text-right font-semibold">UGX {{ number_format($schedule->outstanding_amount, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-slate-500">No installments due today.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[1fr_320px]">
    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Recent Drawer Movement</h2>
            <p class="df-muted">Latest cash in and cash out postings.</p>
        </div>
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Time</th><th>Type</th><th>Reference</th><th>Amount</th><th>Balance</th></tr></thead>
            <tbody>
                @forelse($recentMovements as $movement)
                    <tr class="border-t border-slate-200">
                        <td class="p-3">{{ $movement->created_at->format('H:i') }}</td>
                        <td><span class="rounded px-2 py-1 text-xs font-semibold {{ $movement->type === 'cash_in' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ str($movement->type)->headline() }}</span></td>
                        <td>{{ $movement->reference }}</td>
                        <td>UGX {{ number_format($movement->amount, 2) }}</td>
                        <td>UGX {{ number_format($movement->balance_after, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-8 text-center text-slate-500">No drawer movements yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <aside class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Close & Reconcile</h2>
        <p class="df-muted">Confirm counted cash before ending the session.</p>
        <form method="POST" action="{{ route('teller.close') }}" class="mt-5 grid gap-3">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Closing Balance
                <input name="closing_balance" type="number" min="0" step="100" value="{{ $drawer?->current_balance }}" class="mt-1" placeholder="Closing balance">
            </label>
            <button @disabled(!$session) class="df-action-danger disabled:pointer-events-none disabled:bg-slate-400">Close Drawer</button>
        </form>
        <div class="mt-5 grid gap-2 text-sm">
            <a href="{{ route('reports.show', 'cash-balances') }}" class="df-action-secondary justify-start">Cash Balances</a>
            <a href="{{ route('reports.show', 'user-posted-transactions') }}" class="df-action-secondary justify-start">Posted Transactions</a>
            <a href="{{ route('reports.show', 'expected-loan-payments') }}" class="df-action-secondary justify-start">Expected Loan Payments</a>
        </div>
    </aside>
</div>
@endsection
