@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Savings Deposit</h1>
    <p class="text-sm text-slate-500">{{ $account->account_number }} · {{ $account->client->full_name }} · Balance {{ number_format($account->current_balance, 2) }} UGX</p>
</div>
<form method="POST" action="{{ route('savings-transactions.deposit.store', $account) }}">
    @include('savings-transactions._form', ['button' => 'Post Deposit'])
</form>
@endsection
