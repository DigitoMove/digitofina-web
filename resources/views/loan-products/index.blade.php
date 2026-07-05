@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-semibold">Loan Products</h1>
    @if($canManageLoanProducts)
        <a href="{{ route('loan-products.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">New Product</a>
    @endif
</div>
<form class="mb-4 grid gap-3 rounded border bg-white p-4 md:grid-cols-[1fr_180px_auto]">
    <input name="search" value="{{ request('search') }}" placeholder="Search product or code" class="rounded border-slate-300">
    <select name="status" class="rounded border-slate-300"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
    <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50"><tr><th class="p-3">Product</th><th>Range</th><th>Interest</th><th>Requirements</th><th>Status</th>@if($canManageLoanProducts)<th></th>@endif</tr></thead>
        <tbody>
            @forelse($products as $product)
                <tr class="border-t">
                    <td class="p-3 font-semibold">{{ $product->name }}<div class="text-slate-500">{{ $product->code }}</div></td>
                    <td>UGX {{ number_format($product->min_amount) }} - {{ number_format($product->max_amount) }}</td>
                    <td>{{ $product->annual_interest_rate }}% · {{ str($product->interest_method)->headline() }}</td>
                    <td>{{ $product->require_guarantors ? $product->minimum_guarantors.' guarantor(s)' : 'No guarantors' }} · {{ $product->require_collateral ? 'Collateral' : 'No collateral' }}</td>
                    <td><span class="rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">{{ str($product->status)->headline() }}</span></td>
                    @if($canManageLoanProducts)
                        <td class="p-3 text-right"><a class="text-sm font-semibold text-blue-700" href="{{ route('loan-products.edit', $product) }}">Edit</a></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canManageLoanProducts ? 6 : 5 }}" class="p-6 text-center text-slate-500">No loan products found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
