@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-2xl font-semibold text-slate-950">{{ $account->account_number }}</h1><p class="text-sm text-slate-500">{{ $account->client->full_name }} · {{ $account->product->name }} · {{ $account->branch->name }}</p></div>
    <div class="flex flex-wrap gap-2"><a href="{{ route('savings-transactions.deposit.create', $account) }}" class="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Deposit</a><a href="{{ route('savings-transactions.withdrawal.create', $account) }}" class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white">Withdraw</a><a href="{{ route('savings-transfers.create', ['from' => $account->id]) }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Transfer</a><a href="{{ route('savings-accounts.statement', $account) }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Statement</a></div>
</div>
<div class="grid gap-5 xl:grid-cols-4">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Current balance</div><div class="mt-2 text-3xl font-semibold">{{ number_format($account->current_balance, 2) }} UGX</div></section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Available balance</div><div class="mt-2 text-3xl font-semibold">{{ number_format($account->available_balance, 2) }} UGX</div></section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Minimum balance</div><div class="mt-2 text-3xl font-semibold">{{ number_format($account->product->minimum_balance, 2) }}</div></section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm"><div class="text-sm text-slate-500">Status</div><div class="mt-2 text-3xl font-semibold">{{ str($account->status)->headline() }}</div></section>
</div>
<section class="mt-5 rounded border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold text-slate-950">Recent Transactions</h2>
    <div class="mt-4 overflow-hidden rounded border border-slate-200"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Date</th><th>Reference</th><th>Type</th><th>Amount</th><th>Charges</th><th>Balance After</th><th></th></tr></thead><tbody>@forelse($recentTransactions as $transaction)<tr class="border-t"><td class="p-3">{{ $transaction->transaction_date->format('d M Y') }}</td><td>{{ $transaction->reference }}</td><td>{{ str($transaction->type)->headline() }}</td><td>{{ number_format($transaction->amount, 2) }}</td><td>{{ number_format($transaction->charges, 2) }}</td><td>{{ number_format($transaction->balance_after, 2) }}</td><td><a class="font-medium text-blue-700" href="{{ route('savings-transactions.receipt', $transaction) }}">Receipt</a></td></tr>@empty<tr><td colspan="7" class="p-4 text-slate-500">No transactions posted.</td></tr>@endforelse</tbody></table></div>
    <div class="mt-4">{{ $recentTransactions->links() }}</div>
</section>
@endsection
