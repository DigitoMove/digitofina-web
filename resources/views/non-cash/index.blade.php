@extends('layouts.app')

@section('content')
<div class="mx-auto grid max-w-7xl gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-slate-500">Operations</p>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Non-Cash Transactions</h1>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 shadow-sm">
            Use this for non-cash adjustments, loan clearance, bank/internal receipts, and corrections that should not affect a drawer.
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">1. Select Client Account</h2>
            <form method="GET" action="{{ route('non-cash.index') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                @if($selectedLedger)
                    <input type="hidden" name="chart_account_id" value="{{ $selectedLedger->id }}">
                @endif
                @if($branchId)
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @endif
                <select name="account_type_id" class="rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-100">
                    <option value="">All account types</option>
                    @foreach($accountTypes as $type)
                        <option value="{{ $type->id }}" @selected($selectedAccountTypeId === $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                <input name="account_search" value="{{ request('account_search') }}" class="rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Name, phone, client no. or account no.">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">Search</button>
            </form>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Account</th>
                            <th class="px-3 py-2">Client</th>
                            <th class="px-3 py-2">Balance</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($accountResults as $account)
                            <tr>
                                <td class="px-3 py-2">
                                    <div class="font-medium text-slate-900">{{ $account->account_number }}</div>
                                    <div class="text-xs text-slate-500">{{ $account->accountType?->name }} · {{ $account->branch?->name }}</div>
                                </td>
                                <td class="px-3 py-2 text-slate-700">{{ $account->client?->full_name ?? $account->account_name }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ number_format((float) $account->balance, 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except('account_search'), ['account_id' => $account->id]))) }}" class="rounded-md bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-800">Select</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Search to find an account.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">2. Select Offset Ledger Account</h2>
            <form method="GET" action="{{ route('non-cash.index') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto]">
                @if($selectedAccount)
                    <input type="hidden" name="account_id" value="{{ $selectedAccount->id }}">
                @endif
                @if($selectedAccountTypeId)
                    <input type="hidden" name="account_type_id" value="{{ $selectedAccountTypeId }}">
                @endif
                @if($branchId)
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @endif
                <input name="ledger_search" value="{{ request('ledger_search') }}" class="rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Ledger name, code or type">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">Search</button>
            </form>

            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Ledger</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Branch</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($ledgerResults as $ledger)
                            <tr>
                                <td class="px-3 py-2">
                                    <div class="font-medium text-slate-900">{{ $ledger->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $ledger->code }}</div>
                                </td>
                                <td class="px-3 py-2 capitalize text-slate-700">{{ $ledger->type }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ $ledger->branch?->name }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except('ledger_search'), ['chart_account_id' => $ledger->id]))) }}" class="rounded-md bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-800">Select</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">Search to find a ledger account.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-semibold text-slate-900">3. Post Non-Cash Entry</h2>
        <form method="POST" action="{{ route('non-cash.store') }}" class="mt-4 grid gap-4 lg:grid-cols-4">
            @csrf
            <input type="hidden" name="account_id" value="{{ old('account_id', $selectedAccount?->id) }}">
            <input type="hidden" name="chart_account_id" value="{{ old('chart_account_id', $selectedLedger?->id) }}">

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                <div class="text-xs font-semibold uppercase text-slate-500">Selected account</div>
                <div class="mt-1 font-semibold text-slate-900">{{ $selectedAccount?->account_number ?? 'None selected' }}</div>
                <div class="text-slate-500">{{ $selectedAccount?->client?->full_name ?? $selectedAccount?->account_name }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                <div class="text-xs font-semibold uppercase text-slate-500">Offset ledger</div>
                <div class="mt-1 font-semibold text-slate-900">{{ $selectedLedger?->name ?? 'None selected' }}</div>
                <div class="text-slate-500">{{ $selectedLedger?->code }}</div>
            </div>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Transaction type</span>
                <select name="transaction_type_id" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100">
                    <option value="">Choose type</option>
                    @foreach($transactionTypes as $type)
                        <option value="{{ $type->id }}" @selected((int) old('transaction_type_id') === $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Transaction date</span>
                <input type="date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100">
            </label>
            <label class="grid gap-1 text-sm lg:col-span-2">
                <span class="font-medium text-slate-700">Amount</span>
                <input type="number" name="amount" value="{{ old('amount') }}" min="100" step="100" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="100">
            </label>
            <label class="grid gap-1 text-sm lg:col-span-2">
                <span class="font-medium text-slate-700">Reason / narration</span>
                <input name="description" value="{{ old('description') }}" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="Loan clearance adjustment, bank transfer, internal correction...">
            </label>
            <div class="lg:col-span-4">
                <button class="rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300" @disabled(! $selectedAccount || ! $selectedLedger)>Post Non-Cash Transaction</button>
            </div>
        </form>
    </section>
</div>

<x-transaction-receipt-modal :transaction="$receiptTransaction" :close-url="route('non-cash.index')" />
@endsection
