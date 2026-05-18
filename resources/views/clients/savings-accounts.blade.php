@extends('layouts.app')

@section('content')
@php
    $legacySavingsAccounts = $client->accounts->filter(fn ($account) => $account->accountType?->category === 'savings');
    $hasSavingsAccount = $client->savingsAccounts->isNotEmpty() || $legacySavingsAccounts->isNotEmpty();
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Client Savings Account</h1>
        <p class="df-muted">{{ $client->client_number }} · {{ $client->full_name ?: $client->business_name }} · one savings account per client</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('clients.show', $client) }}" class="df-action-secondary">Client Dashboard</a>
        @unless($hasSavingsAccount)
            <form method="POST" action="{{ route('clients.savings-accounts.generate', $client) }}">
                @csrf
                <button class="df-action-primary">Generate Savings Account</button>
            </form>
        @endunless
    </div>
</div>

<section class="df-panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-700 text-xs uppercase text-white"><tr><th class="px-4 py-3">Account</th><th class="px-4 py-3">Product / Type</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($client->savingsAccounts as $account)
                    <tr class="odd:bg-white even:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-brand-700"><a href="{{ route('savings-accounts.show', $account) }}">{{ $account->account_number }}</a></td>
                        <td class="px-4 py-3">{{ $account->product?->name }}</td>
                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) $account->current_balance, 2) }}</td>
                        <td class="px-4 py-3">{{ str($account->status)->headline() }}</td>
                    </tr>
                @endforeach
                @foreach($legacySavingsAccounts as $account)
                    <tr class="odd:bg-white even:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-brand-700"><a href="{{ route('accounts.show', $account) }}">{{ $account->account_number }}</a></td>
                        <td class="px-4 py-3">{{ $account->accountType?->name }}</td>
                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) $account->balance, 2) }}</td>
                        <td class="px-4 py-3">{{ str($account->status ?? 'active')->headline() }}</td>
                    </tr>
                @endforeach
                @unless($hasSavingsAccount)
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">This client does not have a savings account yet.</td></tr>
                @endunless
            </tbody>
        </table>
    </div>
</section>
@endsection
