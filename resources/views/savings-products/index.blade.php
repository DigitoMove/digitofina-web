@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Savings Products</h1>
        <p class="text-sm text-slate-500">Configure savings terms, charges, interest, and account numbering.</p>
    </div>
    <a href="{{ route('savings-products.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New Product</a>
</div>
<form class="mb-4 grid gap-3 md:grid-cols-[1fr_12rem_auto]">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search product name or code">
    <select name="status" class="rounded border-slate-300"><option value="">All status</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Product</th><th>Minimums</th><th>Interest</th><th>Charges</th><th>Accounts</th><th></th></tr></thead>
        <tbody>
            @forelse($products as $product)
                <tr class="border-t">
                    <td class="p-3 font-medium">{{ $product->code }}<div class="text-slate-500">{{ $product->name }} · {{ str($product->status)->headline() }}</div></td>
                    <td>Open {{ number_format($product->minimum_opening_balance, 2) }}<div class="text-slate-500">Min {{ number_format($product->minimum_balance, 2) }}</div></td>
                    <td>{{ number_format($product->interest_rate, 2) }}%<div class="text-slate-500">{{ str($product->interest_posting_frequency)->headline() }}</div></td>
                    <td>Dep {{ number_format($product->deposit_charge, 2) }}<div class="text-slate-500">W/d {{ number_format($product->withdrawal_charge, 2) }}</div></td>
                    <td>{{ $product->accounts_count }}</td>
                    <td><x-icon-action :href="route('savings-products.edit', $product)" label="Edit savings product" icon="edit" /></td>
                </tr>
            @empty
                <tr><td class="p-4 text-slate-500" colspan="6">No savings products found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
