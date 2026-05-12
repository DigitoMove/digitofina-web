@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Savings Accounts</h1>
        <p class="text-sm text-slate-500">Account balances, status, branch, and product filters.</p>
    </div>
    <a href="{{ route('savings-accounts.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Open Account</a>
</div>
<form class="mb-4 grid gap-3 lg:grid-cols-[1fr_12rem_14rem_12rem_auto]">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search account, client, phone">
    <select name="branch_id" class="rounded border-slate-300"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
    <select name="savings_product_id" class="rounded border-slate-300"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('savings_product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select>
    <select name="status" class="rounded border-slate-300"><option value="">All status</option>@foreach(['pending','active','frozen','dormant','closed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>@endforeach</select>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Account</th><th>Client</th><th>Product</th><th>Balance</th><th>Status</th><th></th></tr></thead><tbody>
        @forelse($accounts as $account)
            <tr class="border-t"><td class="p-3 font-medium">{{ $account->account_number }}<div class="text-slate-500">{{ $account->branch->name }}</div></td><td>{{ $account->client->full_name }}<div class="text-slate-500">{{ $account->client->client_number }}</div></td><td>{{ $account->product->name }}</td><td class="font-semibold">{{ number_format($account->current_balance, 2) }} UGX</td><td>{{ str($account->status)->headline() }}</td><td><a href="{{ route('savings-accounts.show', $account) }}" class="font-medium text-blue-700">View</a></td></tr>
        @empty
            <tr><td colspan="6" class="p-4 text-slate-500">No savings accounts found.</td></tr>
        @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $accounts->links() }}</div>
@endsection
