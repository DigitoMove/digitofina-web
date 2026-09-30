@extends('layouts.app')

@section('content')
@php
    $cancelUrl = $selectedClient ? route('clients.savings-accounts', $selectedClient) : route('accounts.index');
@endphp

<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Open Savings Account</h1>
    <p class="text-sm text-slate-500">Create an account for an active client and optionally post an opening deposit.</p>
</div>
<form method="POST" action="{{ route('savings-accounts.store') }}" class="rounded border border-slate-200 bg-white p-5 shadow-sm">
    @csrf
    <div class="grid gap-4 md:grid-cols-2">
        <label class="text-sm font-medium text-slate-700">Client <x-required-badge /><select name="client_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id', $selectedClient) == $client->id)>{{ $client->client_number }} · {{ $client->full_name }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-slate-700">Branch <x-required-badge /><select name="branch_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $selectedBranch) == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-slate-700">Product <x-required-badge /><select name="savings_product_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('savings_product_id') == $product->id)>{{ $product->name }} · Min {{ number_format($product->minimum_opening_balance, 2) }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-slate-700">Opening date <x-required-badge /><input type="date" name="opening_date" value="{{ old('opening_date', today()->toDateString()) }}" class="mt-1 w-full rounded border-slate-300" required></label>
        <label class="text-sm font-medium text-slate-700">Status<select name="status" class="mt-1 w-full rounded border-slate-300"><option value="active" @selected(old('status', 'active') === 'active')>Active</option><option value="pending" @selected(old('status') === 'pending')>Pending</option><option value="frozen" @selected(old('status') === 'frozen')>Frozen</option></select></label>
        <label class="text-sm font-medium text-slate-700">Opening deposit<input type="number" min="0" step="100" name="opening_deposit" value="{{ old('opening_deposit', 0) }}" class="mt-1 w-full rounded border-slate-300"></label>
        @if($canMarkOrganizationWide)
            <label class="flex items-start gap-3 rounded border border-slate-200 p-3 text-sm font-medium text-slate-700 md:col-span-2">
                <input type="hidden" name="is_organization_wide" value="0">
                <input type="checkbox" name="is_organization_wide" value="1" @checked(old('is_organization_wide')) class="mt-1">
                <span><span class="block">Available across all organization branches</span><span class="font-normal text-slate-500">Authorized cashiers can deposit and withdraw this account from any branch.</span></span>
            </label>
        @endif
    </div>
    <div class="mt-6 flex justify-end gap-3"><a href="{{ $cancelUrl }}" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</a><button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Open Account</button></div>
</form>
@endsection
