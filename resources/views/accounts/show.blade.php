@extends('layouts.app')
@section('content')
<h1 class="text-2xl font-semibold">{{ $account->account_number }}</h1><p class="mb-5 text-sm text-slate-500">{{ $account->account_name }} · {{ $account->client->full_name }}</p>
<div class="grid gap-5 lg:grid-cols-3"><div class="rounded border bg-white p-5"><div class="text-sm text-slate-500">Current balance</div><div class="mt-2 text-3xl font-semibold">{{ number_format($account->balance, 2) }} UGX</div></div><div class="rounded border bg-white p-5 lg:col-span-2"><h2 class="font-semibold">Statement</h2><div class="mt-3 grid gap-2">@foreach($account->transactions->take(10) as $tx)<div class="flex justify-between rounded border p-3 text-sm"><span>{{ $tx->type->name }}<span class="ml-2 text-slate-500">{{ $tx->reference }}</span></span><span>{{ number_format($tx->amount, 2) }}</span></div>@endforeach</div></div></div>
@endsection
