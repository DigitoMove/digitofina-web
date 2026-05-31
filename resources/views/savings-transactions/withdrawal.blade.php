@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Savings Withdrawal</h1>
    <p class="text-sm text-slate-500">{{ $account->account_number }} · {{ $account->client->full_name }} · Available {{ number_format($account->available_balance, 2) }} UGX</p>
</div>
<form method="POST" action="{{ route('savings-transactions.withdrawal.store', $account) }}" data-confirm-title="Review savings withdrawal" data-confirm-message="Please confirm these details before posting. This helps avoid reversal requests." data-confirm-label="Post withdrawal" data-confirm-tone="neutral">
    <input type="hidden" data-confirm-summary-label="Account" data-confirm-summary-value="{{ $account->account_number }} · {{ $account->client->full_name }}">
    <input type="hidden" data-confirm-summary-label="Transaction" data-confirm-summary-value="Savings withdrawal">
    @include('savings-transactions._form', ['button' => 'Post Withdrawal'])
</form>
@endsection
