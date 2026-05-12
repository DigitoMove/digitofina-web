@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-2xl font-semibold text-slate-950">Savings Statement</h1><p class="text-sm text-slate-500">{{ $account->account_number }} · {{ $account->client->full_name }}</p></div>
    <button onclick="window.print()" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white print:hidden">Print</button>
</div>
<form class="mb-4 grid gap-3 md:grid-cols-[12rem_12rem_auto] print:hidden">
    <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded border-slate-300">
    <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded border-slate-300">
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<section class="rounded border border-slate-200 bg-white p-5 shadow-sm print:shadow-none">
    <div class="grid gap-4 border-b border-slate-200 pb-4 text-sm md:grid-cols-4">
        <div><div class="text-slate-500">Product</div><div class="font-semibold">{{ $account->product->name }}</div></div>
        <div><div class="text-slate-500">Branch</div><div class="font-semibold">{{ $account->branch->name }}</div></div>
        <div><div class="text-slate-500">Current balance</div><div class="font-semibold">{{ number_format($account->current_balance, 2) }} UGX</div></div>
        <div><div class="text-slate-500">Generated</div><div class="font-semibold">{{ now()->format('d M Y H:i') }}</div></div>
    </div>
    <div class="mt-4 overflow-hidden rounded border border-slate-200"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Date</th><th>Reference</th><th>Type</th><th>Narration</th><th>Amount</th><th>Charges</th><th>Balance</th></tr></thead><tbody>@forelse($transactions as $transaction)<tr class="border-t"><td class="p-3">{{ $transaction->transaction_date->format('d M Y') }}</td><td>{{ $transaction->reference }}</td><td>{{ str($transaction->type)->headline() }}</td><td>{{ $transaction->narration }}</td><td>{{ number_format($transaction->amount, 2) }}</td><td>{{ number_format($transaction->charges, 2) }}</td><td>{{ number_format($transaction->balance_after, 2) }}</td></tr>@empty<tr><td colspan="7" class="p-4 text-slate-500">No statement transactions found.</td></tr>@endforelse</tbody></table></div>
    <div class="mt-4 print:hidden">{{ $transactions->links() }}</div>
</section>
@endsection
