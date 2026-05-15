@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Reports</h1>
        <p class="df-muted">Operational, portfolio, accounting, and audit views for {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}.</p>
    </div>
    <form class="df-panel grid grid-cols-2 gap-2 p-2 md:grid-cols-[160px_160px_auto]">
        <input type="date" name="from" value="{{ $from->toDateString() }}">
        <input type="date" name="to" value="{{ $to->toDateString() }}">
        <button class="df-action-primary">Run</button>
    </form>
</div>

@php
    $journalBalance = $summary['journal_debits'] - $summary['journal_credits'];
    $cards = [
        ['Clients', number_format($summary['clients']), 'bg-brand-50 text-brand-800'],
        ['Active accounts', number_format($summary['active_accounts']), 'bg-forest-50 text-forest-700'],
        ['Cash in', number_format($summary['cash_in'], 2).' UGX', 'bg-forest-50 text-forest-700'],
        ['Cash out', number_format($summary['cash_out'], 2).' UGX', 'bg-sun-50 text-sun-600'],
        ['Loan portfolio', number_format($summary['portfolio'], 2).' UGX', 'bg-brand-50 text-brand-800'],
        ['Arrears', number_format($summary['arrears'], 2).' UGX', 'bg-coral-50 text-coral-700'],
        ['Open drawers', number_format($summary['open_drawers']), 'bg-slate-100 text-slate-700'],
        ['Journal balance', number_format($journalBalance, 2).' UGX', 'bg-slate-100 text-slate-700'],
    ];

    $cashOverview = [
        ['Cash in', $summary['cash_in'], 'bg-forest-500'],
        ['Cash out', $summary['cash_out'], 'bg-sun-400'],
        ['Arrears', $summary['arrears'], 'bg-coral-500'],
        ['Portfolio', $summary['portfolio'], 'bg-brand-600'],
    ];
    $cashOverviewMax = max(1, $summary['cash_in'], $summary['cash_out'], $summary['arrears'], $summary['portfolio']);
    $loanStatusMax = max(1, $loanStatuses->max('total') ?? 0);
    $accountBalanceMax = max(1, $accountBalances->max('total_balance') ?? 0);
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as [$label, $value, $badgeClass])
        <div class="df-panel df-hover-lift p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-xl font-semibold text-slate-950">{{ $value }}</div>
                </div>
                <span class="grid h-10 w-10 place-items-center rounded-lg {{ $badgeClass }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 19V5m0 14h14M9 16v-5m4 5V8m4 8v-7"/></svg>
                </span>
            </div>
        </div>
    @endforeach
</div>

<div data-df-tabs class="mt-6 grid gap-5">
    <div class="df-tablist" role="tablist">
        <button type="button" class="df-tab" data-df-tab-target="overview">Overview</button>
        <button type="button" class="df-tab" data-df-tab-target="teller">Teller</button>
        <button type="button" class="df-tab" data-df-tab-target="accounts">Accounts</button>
        <button type="button" class="df-tab" data-df-tab-target="loans">Loans & Arrears</button>
        <button type="button" class="df-tab" data-df-tab-target="ledger">Ledger & Audit</button>
    </div>

    <section data-df-tab-panel="overview" class="grid gap-5 xl:grid-cols-3">
        <div class="df-panel p-5 xl:col-span-2">
            <h2 class="text-lg font-semibold text-slate-950">Financial Movement</h2>
            <p class="df-muted">Cash, arrears, and portfolio values in the selected report window.</p>
            <div class="mt-6 space-y-5">
                @foreach ($cashOverview as [$label, $amount, $barClass])
                    @php($width = max(4, ($amount / $cashOverviewMax) * 100))
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium text-slate-700">{{ $label }}</span>
                            <span class="text-slate-500">{{ number_format($amount, 0) }} UGX</span>
                        </div>
                        <div class="h-4 rounded-full bg-slate-100">
                            <div class="h-4 rounded-full {{ $barClass }}" style="width: {{ $width }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="df-panel p-5">
            <h2 class="text-lg font-semibold text-slate-950">Loan Status Mix</h2>
            <p class="df-muted">Distribution by loan lifecycle status.</p>
            <div class="mt-6 space-y-4">
                @foreach ($loanStatuses as $status)
                    @php($width = max(6, ($status->total / $loanStatusMax) * 100))
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                            <span class="font-medium text-slate-700">{{ str($status->status)->headline() }}</span>
                            <span class="text-slate-500">{{ number_format($status->total) }}</span>
                        </div>
                        <div class="h-3 rounded-full bg-slate-100">
                            <div class="h-3 rounded-full bg-brand-600" style="width: {{ $width }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section data-df-tab-panel="teller" class="grid gap-5 xl:grid-cols-2">
        <div class="df-panel overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Daily Teller Report</h2>
                <p class="df-muted">Cash drawer movements and transaction references.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Drawer</th><th>Teller</th><th>Type</th><th>Amount</th><th>Balance</th></tr></thead>
                    <tbody>
                        @foreach ($cashMovements as $movement)
                            <tr class="border-t border-slate-200"><td class="p-3">{{ $movement->session->drawer->name }}</td><td>{{ $movement->session->user->name }}</td><td>{{ str($movement->type)->headline() }}</td><td>{{ number_format($movement->amount, 2) }}</td><td>{{ number_format($movement->balance_after, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="df-panel overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Cash Transactions Report</h2>
                <p class="df-muted">Deposits, withdrawals, repayments, shares deposits, and charges.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Reference</th><th>Client</th><th>Type</th><th>Amount</th><th>Teller</th></tr></thead>
                    <tbody>
                        @foreach ($cashTransactions as $tx)
                            <tr class="border-t border-slate-200"><td class="p-3 font-medium">{{ $tx->reference }}</td><td>{{ $tx->account?->client?->full_name }}</td><td>{{ $tx->type->name }}</td><td>{{ number_format($tx->amount, 2) }}</td><td>{{ $tx->user->name }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="df-panel overflow-hidden xl:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Non-Cash Transactions Report</h2>
                <p class="df-muted">Transfers, disbursements, journal-driven postings, and non-cash charges.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Reference</th><th>Client</th><th>Type</th><th>Amount</th><th>User</th></tr></thead>
                    <tbody>
                        @foreach ($nonCashTransactions as $tx)
                            <tr class="border-t border-slate-200"><td class="p-3 font-medium">{{ $tx->reference }}</td><td>{{ $tx->account?->client?->full_name }}</td><td>{{ $tx->type->name }}</td><td>{{ number_format($tx->amount, 2) }}</td><td>{{ $tx->user->name }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section data-df-tab-panel="accounts" class="grid gap-5 xl:grid-cols-2">
        <div class="df-panel p-5 xl:col-span-2">
            <h2 class="text-lg font-semibold text-slate-950">Account Balance Graph</h2>
            <p class="df-muted">Balances grouped by product category.</p>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($accountBalances as $row)
                    @php($width = max(4, ($row->total_balance / $accountBalanceMax) * 100))
                    <div class="df-soft-panel p-4">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="font-semibold text-slate-800">{{ $row->accountType->name }}</span>
                            <span class="text-slate-500">{{ number_format($row->accounts_count) }} accounts</span>
                        </div>
                        <div class="mt-4 h-3 rounded-full bg-white">
                            <div class="h-3 rounded-full bg-forest-500" style="width: {{ $width }}%"></div>
                        </div>
                        <div class="mt-2 text-sm font-medium text-slate-600">{{ number_format($row->total_balance, 2) }} UGX</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="df-panel overflow-hidden xl:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Account Balances Report</h2>
                <p class="df-muted">Balances grouped by product category.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Account type</th><th>Accounts</th><th>Total balance</th></tr></thead>
                    <tbody>
                        @foreach ($accountBalances as $row)
                            <tr class="border-t border-slate-200"><td class="p-3">{{ $row->accountType->name }}</td><td>{{ number_format($row->accounts_count) }}</td><td>{{ number_format($row->total_balance, 2) }} UGX</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section data-df-tab-panel="loans" class="grid gap-5 xl:grid-cols-2">
        <div class="df-panel overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Loan Portfolio Report</h2>
                <p class="df-muted">Status distribution and principal exposure.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Status</th><th>Loans</th><th>Principal balance</th></tr></thead>
                    <tbody>
                        @foreach ($loanStatuses as $status)
                            <tr class="border-t border-slate-200"><td class="p-3">{{ str($status->status)->headline() }}</td><td>{{ number_format($status->total) }}</td><td>{{ number_format($status->principal_balance, 2) }} UGX</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="df-panel overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Loan Arrears Report</h2>
                <p class="df-muted">Overdue installments requiring follow-up.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Client</th><th>Loan</th><th>Due date</th><th>Outstanding</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($arrears as $schedule)
                            <tr class="border-t border-slate-200"><td class="p-3">{{ $schedule->loan->client->full_name }}</td><td>{{ $schedule->loan->loan_number }}</td><td>{{ $schedule->due_date->format('d M Y') }}</td><td>{{ number_format($schedule->outstanding_amount, 2) }}</td><td>{{ str($schedule->status)->headline() }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section data-df-tab-panel="ledger" class="grid gap-5 xl:grid-cols-2">
        <div class="df-panel overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">General Ledger / Trial Balance</h2>
                <p class="df-muted">Journal entries generated by financial operations.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Reference</th><th>Code</th><th>Ledger</th><th>Entry</th><th>Amount</th><th>Memo</th></tr></thead>
                    <tbody>
                        @foreach ($journalEntries as $entry)
                            <tr class="border-t border-slate-200"><td class="p-3">{{ $entry->reference }}</td><td>{{ $entry->chartAccount?->code ?? 'N/A' }}</td><td>{{ $entry->chartAccount?->name ?? $entry->account_name }}</td><td>{{ ucfirst($entry->entry_type) }}</td><td>{{ number_format($entry->amount, 2) }}</td><td>{{ $entry->memo }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="df-panel overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="font-semibold text-slate-950">Audit Logs</h2>
                <p class="df-muted">Sensitive activity trail for operational review.</p>
            </div>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Action</th><th>User</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($auditLogs as $log)
                            <tr class="border-t border-slate-200"><td class="p-3 font-medium">{{ $log->action }}</td><td>{{ $log->user?->name ?? 'System' }}</td><td>{{ $log->created_at->format('d M Y H:i') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
