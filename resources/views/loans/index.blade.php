@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold">Loans</h1>
        @if($mustSearchLoanDirectory)
            <p class="text-sm text-slate-500">Search for a client or loan to view the loan details.</p>
        @endif
    </div>
    <div class="flex gap-2">
        @if(! auth()->user()->isTellerCashier() && (auth()->user()->canPerform('manage-loans') || auth()->user()->canPerform('view-reports') || auth()->user()->canPerform('view-arrears')))
            <a href="{{ route('arrears.index') }}" class="rounded border px-4 py-2 text-sm font-semibold">Arrears</a>
        @endif
        @can('create', \App\Models\Loan::class)
            <a href="{{ route('loans.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">New Application</a>
        @endcan
    </div>
</div>
<form class="mb-4 grid gap-3 rounded border bg-white p-4 {{ $mustSearchLoanDirectory ? 'md:grid-cols-[1fr_auto]' : 'md:grid-cols-[1fr_150px_170px_200px_200px_auto]' }}">
    <input name="search" value="{{ request('search') }}" placeholder="Search loan or client" class="rounded border-slate-300">
    @if(! $mustSearchLoanDirectory)
        <select name="status" class="rounded border-slate-300"><option value="">All statuses</option>@foreach(['approved','active','in_arrears','closed','fully_paid'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>@endforeach</select>
        <select name="loan_type" class="rounded border-slate-300"><option value="">All types</option>@foreach(['standard' => 'Officer loans', 'commercial' => 'Commercial loans'] as $type => $label)<option value="{{ $type }}" @selected(request('loan_type') === $type)>{{ $label }}</option>@endforeach</select>
        <select name="officer_id" class="rounded border-slate-300"><option value="">All officers</option>@foreach($officers as $officer)<option value="{{ $officer->id }}" @selected((int) request('officer_id') === $officer->id)>{{ $officer->name }}</option>@endforeach</select>
        <select name="loan_product_id" class="rounded border-slate-300"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((int) request('loan_product_id') === $product->id)>{{ $product->name }}</option>@endforeach</select>
    @endif
    <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">{{ $mustSearchLoanDirectory ? 'Search' : 'Filter' }}</button>
</form>
<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50"><tr><th class="p-3">Loan</th><th>Client</th><th>Product</th><th>Amounts</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($loans as $loan)
                <tr class="border-t">
                    <td class="p-3"><a class="font-semibold text-blue-700" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a><div class="text-slate-500">{{ optional($loan->officer)->name }}</div></td>
                    <td>{{ $loan->client->full_name }}</td>
                    <td>{{ $loan->product->name }}<div class="text-xs text-slate-500">{{ str($loan->loan_type ?? 'standard')->headline() }}</div></td>
                    <td><div>Requested · UGX {{ number_format($loan->requested_amount, 2) }}</div><div class="text-xs font-semibold text-slate-600">Outstanding · UGX {{ number_format($loan->principal_balance + $loan->interest_balance + $loan->fees_balance + $loan->penalty_balance, 2) }}</div></td>
                    <td><span class="rounded bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-800">{{ str($loan->status)->headline() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-slate-500">{{ $mustSearchLoanDirectory && ! $hasLoanSearch ? 'Search for a client or loan to view loan details.' : 'No loans found for this search.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($hasLoanSearch || ! $mustSearchLoanDirectory)
    <div class="mt-4">{{ $loans->links() }}</div>
@endif
@endsection
