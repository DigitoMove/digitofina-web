@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Client Accounts</h1>
        <p class="df-muted">{{ $client->client_number }} · {{ $client->full_name ?: $client->business_name }} · {{ $client->branch?->name }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('clients.show', $client) }}" class="df-action-secondary">Client Dashboard</a>
        <a href="{{ route('clients.statements', $client) }}" class="df-action-secondary">Download Statements</a>
        <a href="{{ route('accounts.create', ['client_id' => $client->id]) }}" class="df-action-primary">Open Account</a>
    </div>
</div>

<section class="grid gap-5 xl:grid-cols-2">
    <div class="df-panel overflow-hidden">
        <h2 class="px-5 py-4 font-semibold text-slate-950">Operating Accounts</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-700 text-xs uppercase text-white"><tr><th class="px-4 py-3">Account</th><th class="px-4 py-3">Type</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($client->accounts as $account)
                        <tr class="odd:bg-white even:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-brand-700"><a href="{{ route('accounts.show', $account) }}">{{ $account->account_number }}</a></td>
                            <td class="px-4 py-3">{{ $account->accountType?->name }}</td>
                            <td class="px-4 py-3 text-right">UGX {{ number_format((float) $account->balance, 2) }}</td>
                            <td class="px-4 py-3">{{ str($account->status ?? 'active')->headline() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No operating accounts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="df-panel overflow-hidden">
        <h2 class="px-5 py-4 font-semibold text-slate-950">Savings Product Packages</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-700 text-xs uppercase text-white"><tr><th class="px-4 py-3">Account</th><th class="px-4 py-3">Product</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($client->savingsAccounts as $account)
                        <tr class="odd:bg-white even:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-brand-700"><a href="{{ route('savings-accounts.show', $account) }}">{{ $account->account_number }}</a></td>
                            <td class="px-4 py-3">{{ $account->product?->name }}</td>
                            <td class="px-4 py-3 text-right">UGX {{ number_format((float) $account->current_balance, 2) }}</td>
                            <td class="px-4 py-3">{{ str($account->status)->headline() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No savings product package found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
