@extends('layouts.app')

@section('content')
<section class="mx-auto max-w-xl rounded border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
    <div class="text-center">
        <div class="text-xl font-bold">DigitoFina</div>
        <div class="text-sm text-slate-500">Savings Transaction Receipt</div>
    </div>
    <dl class="mt-6 grid gap-3 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Receipt number</dt><dd class="font-semibold">{{ $transaction->reference }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Branch</dt><dd>{{ $transaction->branch->name }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Client</dt><dd>{{ $transaction->account->client->full_name }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Client number</dt><dd>{{ $transaction->account->client->client_number }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Account</dt><dd>{{ $transaction->account->account_number }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Transaction type</dt><dd>{{ str($transaction->type)->headline() }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Amount</dt><dd class="font-semibold">{{ number_format($transaction->amount, 2) }} UGX</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Charges</dt><dd>{{ number_format($transaction->charges, 2) }} UGX</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Payment method</dt><dd>{{ str($transaction->payment_method)->headline() }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Balance after</dt><dd>{{ number_format($transaction->balance_after, 2) }} UGX</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Teller/staff</dt><dd>{{ $transaction->teller?->name ?? 'System' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-slate-500">Date/time</dt><dd>{{ $transaction->transaction_date->format('d M Y') }} {{ $transaction->created_at->format('H:i:s') }}</dd></div>
    </dl>
    <div class="mt-8 border-t border-slate-200 pt-6 text-sm text-slate-500">Signature: ______________________________</div>
    <p class="mt-4 text-center text-xs text-slate-500">System-generated receipt. Keep for your records.</p>
    <button onclick="window.print()" class="mt-6 w-full rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white print:hidden">Print receipt</button>
</section>
@endsection
