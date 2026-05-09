@extends('layouts.app')
@section('content')
<section class="mx-auto max-w-xl rounded border bg-white p-6 shadow-sm print:shadow-none">
    <div class="text-center"><div class="text-xl font-bold">DigitoFina</div><div class="text-sm text-slate-500">Transaction Receipt</div></div>
    <dl class="mt-6 grid gap-3 text-sm"><div class="flex justify-between"><dt>Reference</dt><dd class="font-semibold">{{ $transaction->reference }}</dd></div><div class="flex justify-between"><dt>Type</dt><dd>{{ $transaction->type->name }}</dd></div><div class="flex justify-between"><dt>Client</dt><dd>{{ $transaction->account?->client?->full_name }}</dd></div><div class="flex justify-between"><dt>Account</dt><dd>{{ $transaction->account?->account_number }}</dd></div><div class="flex justify-between"><dt>Amount</dt><dd class="font-semibold">{{ number_format($transaction->amount, 2) }} {{ $transaction->currency }}</dd></div><div class="flex justify-between"><dt>Balance after</dt><dd>{{ number_format($transaction->balance_after, 2) }} {{ $transaction->currency }}</dd></div><div class="flex justify-between"><dt>Teller</dt><dd>{{ $transaction->user->name }}</dd></div></dl>
    <button onclick="window.print()" class="mt-6 w-full rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white print:hidden">Print receipt</button>
</section>
@endsection
