@extends('layouts.app')

@section('content')
@php
    $basicSavingsAccounts = $client->accounts->filter(fn ($account) => $account->accountType?->category === 'savings');
    $savingsPackages = $client->savingsAccounts;
    $hasSavingsAccount = $basicSavingsAccounts->isNotEmpty();
    $canRemoveSavingsPackage = auth()->user()->hasRole('super-admin') || auth()->user()->canPerform('manage-accounts');
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Client Savings Account</h1>
        <p class="df-muted">{{ $client->client_number }} · {{ $client->full_name ?: $client->business_name }} · basic account plus intentional product packages</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('clients.show', $client) }}" class="df-action-secondary">Client Dashboard</a>
        @if($canRemoveSavingsPackage)
            <a href="{{ route('savings-accounts.create', ['client_id' => $client->id]) }}" class="df-action-primary">Attach Package</a>
        @endif
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
            <thead class="bg-slate-700 text-xs uppercase text-white"><tr><th class="px-4 py-3">Account</th><th class="px-4 py-3">Type</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($basicSavingsAccounts as $account)
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

<section class="df-panel mt-5 overflow-hidden">
    <div class="border-b border-slate-100 px-5 py-4">
        <h2 class="font-semibold text-slate-950">Savings Product Packages</h2>
        <p class="mt-1 text-sm text-slate-500">Optional investment-style savings products. Attach these deliberately; they are not generated automatically with the basic savings account.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-700 text-xs uppercase text-white"><tr><th class="px-4 py-3">Package Account</th><th class="px-4 py-3">Product</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($savingsPackages as $account)
                    <tr class="odd:bg-white even:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-brand-700"><a href="{{ route('savings-accounts.show', $account) }}">{{ $account->account_number }}</a></td>
                        <td class="px-4 py-3">{{ $account->product?->name }}</td>
                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) $account->current_balance, 2) }}</td>
                        <td class="px-4 py-3">{{ str($account->status)->headline() }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($canRemoveSavingsPackage)
                                <form method="POST" action="{{ route('savings-accounts.destroy', $account) }}" data-confirm-title="Remove savings package" data-confirm-message="Remove this savings product package? The basic savings account will remain." data-confirm-label="Remove package" data-confirm-tone="warning">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Remove Package</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No savings product package is attached to this client.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
