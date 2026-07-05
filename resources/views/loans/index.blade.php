@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap justify-between gap-3">
    <h1 class="text-2xl font-semibold">Loans</h1>
    <div class="flex gap-2">
        @if(auth()->user()->canPerform('manage-loans') || auth()->user()->canPerform('view-reports') || auth()->user()->canPerform('view-arrears'))
            <a href="{{ route('arrears.index') }}" class="rounded border px-4 py-2 text-sm font-semibold">Arrears</a>
        @endif
        @can('create', \App\Models\Loan::class)
            <a href="{{ route('loans.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">New Application</a>
        @endcan
    </div>
</div>
<form class="mb-4 grid gap-3 rounded border bg-white p-4 md:grid-cols-[1fr_150px_170px_200px_200px_auto]">
    <input name="search" value="{{ request('search') }}" placeholder="Search loan or client" class="rounded border-slate-300">
    <select name="status" class="rounded border-slate-300"><option value="">All statuses</option>@foreach(['approved','active','in_arrears','closed','fully_paid'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>@endforeach</select>
    <select name="loan_type" class="rounded border-slate-300"><option value="">All types</option>@foreach(['standard' => 'Officer loans', 'commercial' => 'Commercial loans'] as $type => $label)<option value="{{ $type }}" @selected(request('loan_type') === $type)>{{ $label }}</option>@endforeach</select>
    <select name="officer_id" class="rounded border-slate-300"><option value="">All officers</option>@foreach($officers as $officer)<option value="{{ $officer->id }}" @selected((int) request('officer_id') === $officer->id)>{{ $officer->name }}</option>@endforeach</select>
    <select name="loan_product_id" class="rounded border-slate-300"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((int) request('loan_product_id') === $product->id)>{{ $product->name }}</option>@endforeach</select>
    <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50"><tr><th class="p-3">Loan</th><th>Client</th><th>Product</th><th>Type</th><th>Requested</th><th>Outstanding</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($loans as $loan)
                <tr class="border-t">
                    <td class="p-3"><a class="font-semibold text-blue-700" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a><div class="text-slate-500">{{ optional($loan->officer)->name }}</div></td>
                    <td>{{ $loan->client->full_name }}</td>
                    <td>{{ $loan->product->name }}</td>
                    <td>{{ str($loan->loan_type ?? 'standard')->headline() }}</td>
                    <td>UGX {{ number_format($loan->requested_amount, 2) }}</td>
                    <td>UGX {{ number_format($loan->principal_balance + $loan->interest_balance + $loan->fees_balance + $loan->penalty_balance, 2) }}</td>
                    <td><span class="rounded bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-800">{{ str($loan->status)->headline() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-6 text-center text-slate-500">No loans found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $loans->links() }}</div>
@endsection
