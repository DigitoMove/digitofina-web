@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">{{ $account->account_number }}</h1>
        <p class="df-muted">{{ $account->client->full_name }} · {{ $account->product->name }} · {{ $account->branch->name }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('savings-transactions.deposit.create', $account) }}" class="df-action-primary">Deposit</a>
        <a href="{{ route('savings-transactions.withdrawal.create', $account) }}" class="df-action-secondary">Withdraw</a>
        <a href="{{ route('savings-transfers.create', ['from' => $account->id]) }}" class="df-action-secondary">Transfer</a>
        <a href="{{ route('savings-accounts.statement', $account) }}" class="df-action-secondary">Statement</a>
    </div>
</div>

<div class="grid gap-4 md:grid-cols-4">
    <section class="df-panel df-hover-lift p-5">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current balance</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($account->current_balance, 2) }} UGX</div>
    </section>
    <section class="df-panel df-hover-lift p-5">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Available balance</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($account->available_balance, 2) }} UGX</div>
    </section>
    <section class="df-panel df-hover-lift p-5">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Minimum balance</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ number_format($account->product->minimum_balance, 2) }} UGX</div>
    </section>
    <section class="df-panel df-hover-lift p-5">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
        <div class="mt-2"><span class="df-badge {{ $account->status === 'active' ? 'border-forest-500/20 bg-forest-50 text-forest-700' : 'border-sun-400/20 bg-sun-50 text-sun-600' }}">{{ str($account->status)->headline() }}</span></div>
    </section>
</div>

<div data-df-tabs class="mt-5 grid gap-5">
    <div class="df-tablist" role="tablist">
        <button type="button" class="df-tab" data-df-tab-target="overview">Overview</button>
        <button type="button" class="df-tab" data-df-tab-target="transactions">Transactions</button>
        <button type="button" class="df-tab" data-df-tab-target="controls">Controls</button>
    </div>

    <section data-df-tab-panel="overview" class="grid gap-5 lg:grid-cols-3">
        <div class="df-panel p-5 lg:col-span-2">
            <h2 class="font-semibold text-slate-950">Account Profile</h2>
            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Client</dt><dd class="mt-1 font-semibold">{{ $account->client->full_name }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Product</dt><dd class="mt-1">{{ $account->product->name }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Branch</dt><dd class="mt-1">{{ $account->branch->name }}</dd></div>
                <div class="df-soft-panel p-3"><dt class="text-slate-500">Opened</dt><dd class="mt-1">{{ $account->opening_date?->format('d M Y') ?? 'N/A' }}</dd></div>
            </dl>
        </div>
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Quick Workflow</h2>
            <div class="mt-4 grid gap-3">
                <a href="{{ route('savings-transactions.deposit.create', $account) }}" class="df-step is-complete">1. Receive deposit</a>
                <a href="{{ route('savings-transactions.withdrawal.create', $account) }}" class="df-step">2. Process withdrawal</a>
                <a href="{{ route('savings-transfers.create', ['from' => $account->id]) }}" class="df-step">3. Transfer funds</a>
                <a href="{{ route('savings-accounts.statement', $account) }}" class="df-step">4. Print statement</a>
            </div>
        </div>
    </section>

    <section data-df-tab-panel="transactions" class="df-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Recent Transactions</h2>
                <p class="df-muted">Ledger-impacting savings activity with receipt access.</p>
            </div>
            <a href="{{ route('savings-accounts.statement', $account) }}" class="df-action-secondary">Download Statement</a>
        </div>
        <div class="mt-4 overflow-hidden rounded-lg border border-slate-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr><th class="p-3">Date</th><th>Reference</th><th>Type</th><th>Amount</th><th>Charges</th><th>Balance After</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $transaction)
                        <tr class="border-t border-slate-200">
                            <td class="p-3">{{ $transaction->transaction_date->format('d M Y') }}</td>
                            <td class="font-medium">{{ $transaction->reference }}</td>
                            <td>{{ str($transaction->type)->headline() }}</td>
                            <td>{{ number_format($transaction->amount, 2) }}</td>
                            <td>{{ number_format($transaction->charges, 2) }}</td>
                            <td>{{ number_format($transaction->balance_after, 2) }}</td>
                            <td><a class="font-semibold text-blue-700" href="{{ route('savings-transactions.receipt', $transaction) }}">Receipt</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-4 text-slate-500">No transactions posted.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $recentTransactions->links() }}</div>
    </section>

    <section data-df-tab-panel="controls" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Transaction Controls</h2>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
            <div class="df-soft-panel p-4 text-sm">
                <div class="font-semibold text-slate-950">Minimum Balance Rule</div>
                <p class="mt-2 text-slate-600">Withdrawals are checked against {{ number_format($account->product->minimum_balance, 2) }} UGX before posting.</p>
            </div>
            <div class="df-soft-panel p-4 text-sm">
                <div class="font-semibold text-slate-950">Product Charges</div>
                <p class="mt-2 text-slate-600">Deposit, withdrawal, and maintenance charges follow the product configuration.</p>
            </div>
            <div class="df-soft-panel p-4 text-sm">
                <div class="font-semibold text-slate-950">Audit Trail</div>
                <p class="mt-2 text-slate-600">Each posted transaction records balances before and after, teller, branch, and receipt reference.</p>
            </div>
        </div>
    </section>
</div>
@endsection
