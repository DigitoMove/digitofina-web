@extends('layouts.app')
@section('content')
<div class="mb-5 flex justify-between"><h1 class="text-2xl font-semibold">Loan Products</h1><a href="{{ route('loan-products.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">New Product</a></div>
<div class="overflow-hidden rounded border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-3">Product</th><th>Range</th><th>Rate</th><th>Frequency</th></tr></thead><tbody>@foreach($products as $product)<tr class="border-t"><td class="p-3 font-semibold">{{ $product->name }}<div class="text-slate-500">{{ $product->code }}</div></td><td>{{ number_format($product->min_amount) }} - {{ number_format($product->max_amount) }}</td><td>{{ $product->annual_interest_rate }}%</td><td>{{ ucfirst($product->repayment_frequency) }}</td></tr>@endforeach</tbody></table></div><div class="mt-4">{{ $products->links() }}</div>
@endsection
