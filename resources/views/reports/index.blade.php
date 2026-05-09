@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Reports</h1>
        <p class="text-sm text-slate-500">Operational, portfolio, accounting, and audit views for {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}.</p>
    </div>
    <form class="grid grid-cols-2 gap-2 md:grid-cols-[160px_160px_auto]">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="rounded border-slate-300 text-sm">
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="rounded border-slate-300 text-sm">
        <button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Run</button>
    </form>
</div>

@php
    $cards = [
        ['Clients', number_format($summary['clients'])],
        ['Active accounts', number_format($summary['active_accounts'])],
        ['Cash in', number_format($summary['cash_in'], 2).' UGX'],
        ['Cash out', number_format($summary['cash_out'], 2).' UGX'],
        ['Loan portfolio', number_format($summary['portfolio'], 2).' UGX'],
        ['Arrears', number_format($summary['arrears'], 2).' UGX'],
        ['Open drawers', number_format($summary['open_drawers'])],
        ['Journal balance', number_format($summary['journal_debits'] - $summary['journal_credits'], 2).' UGX'],
    ];
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as [$label, $value])
        <div class="rounded border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
            <div class="mt-2 text-xl font-semibold text-slate-950">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-2">
    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Daily Teller Report</h2>
            <p class="text-sm text-slate-500">Cash drawer movements and transaction references.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Account Balances Report</h2>
            <p class="text-sm text-slate-500">Balances grouped by product category.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Cash Transactions Report</h2>
            <p class="text-sm text-slate-500">Deposits, withdrawals, repayments, shares deposits, and charges.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Non-Cash Transactions Report</h2>
            <p class="text-sm text-slate-500">Transfers, loan disbursements, journal-driven postings, and non-cash charges.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Loan Portfolio Report</h2>
            <p class="text-sm text-slate-500">Status distribution and principal exposure.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Loan Arrears Report</h2>
            <p class="text-sm text-slate-500">Overdue installments requiring follow-up.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">General Ledger / Trial Balance</h2>
            <p class="text-sm text-slate-500">Journal entries generated by financial operations.</p>
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
    </section>

    <section class="rounded border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold">Audit Logs</h2>
            <p class="text-sm text-slate-500">Sensitive activity trail for operational review.</p>
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
    </section>
</div>
@endsection
