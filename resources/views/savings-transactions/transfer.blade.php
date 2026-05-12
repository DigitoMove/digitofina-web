@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Savings Transfer</h1>
    <p class="text-sm text-slate-500">Move funds between two active savings accounts.</p>
</div>
<form method="POST" action="{{ route('savings-transfers.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @csrf
    <div class="grid gap-4 md:grid-cols-2">
        <label class="text-sm font-medium text-slate-700">From account<select name="from_savings_account_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('from_savings_account_id', $fromAccountId) == $account->id)>{{ $account->account_number }} · {{ $account->client->full_name }} · {{ number_format($account->current_balance, 2) }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-slate-700">To account<select name="to_savings_account_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('to_savings_account_id') == $account->id)>{{ $account->account_number }} · {{ $account->client->full_name }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-slate-700">Amount<input type="number" step="0.01" name="amount" value="{{ old('amount') }}" class="mt-1 w-full rounded border-slate-300" required></label>
        <label class="text-sm font-medium text-slate-700">Narration<input name="narration" value="{{ old('narration') }}" class="mt-1 w-full rounded border-slate-300"></label>
    </div>
    <button class="mt-5 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Post Transfer</button>
</form>
@endsection
