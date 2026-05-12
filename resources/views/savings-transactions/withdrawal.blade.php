@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Savings Withdrawal</h1>
    <p class="text-sm text-slate-500">{{ $account->account_number }} · {{ $account->client->full_name }} · Available {{ number_format($account->available_balance, 2) }} UGX</p>
</div>
<form method="POST" action="{{ route('savings-transactions.withdrawal.store', $account) }}">
    @include('savings-transactions._form', ['button' => 'Post Withdrawal'])
</form>
@endsection
