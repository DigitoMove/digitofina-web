@extends('layouts.app')
@section('content')
<div class="mb-5 flex justify-between"><h1 class="text-2xl font-semibold">Loans</h1><a href="{{ route('loans.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">New Application</a></div>
<div class="overflow-hidden rounded border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-3">Loan</th><th>Client</th><th>Product</th><th>Amount</th><th>Status</th></tr></thead><tbody>@foreach($loans as $loan)<tr class="border-t"><td class="p-3"><a class="font-semibold text-blue-700" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a></td><td>{{ $loan->client->full_name }}</td><td>{{ $loan->product->name }}</td><td>{{ number_format($loan->requested_amount, 2) }}</td><td>{{ str($loan->status)->headline() }}</td></tr>@endforeach</tbody></table></div><div class="mt-4">{{ $loans->links() }}</div>
@endsection
