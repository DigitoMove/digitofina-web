@extends('layouts.app')
@section('content')
<div class="mb-5 flex items-center justify-between gap-3">
    <h1 class="text-2xl font-semibold">Loan Application</h1>
    <a href="{{ route('loans.index') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back</a>
</div>
<form method="POST" action="{{ route('loans.store') }}" class="rounded border bg-white p-5">
    @csrf
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <label class="text-sm font-medium">Client<select name="client_id" class="mt-1 w-full rounded border-slate-300">@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->client_number }} - {{ $client->full_name }}</option>@endforeach</select></label>
        <label class="text-sm font-medium">Product<select name="loan_product_id" class="mt-1 w-full rounded border-slate-300">@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('loan_product_id') == $product->id)>{{ $product->name }} · {{ $product->annual_interest_rate }}%</option>@endforeach</select></label>
        <label class="text-sm font-medium">Requested amount<input name="requested_amount" value="{{ old('requested_amount') }}" type="number" step="0.01" class="mt-1 w-full rounded border-slate-300" required></label>
        <label class="text-sm font-medium">Repayment period<input name="term_periods" value="{{ old('term_periods', 12) }}" type="number" class="mt-1 w-full rounded border-slate-300" required></label>
        <label class="text-sm font-medium">Frequency<select name="repayment_frequency" class="mt-1 w-full rounded border-slate-300">@foreach(['monthly','weekly','bi-weekly','quarterly','daily'] as $frequency)<option value="{{ $frequency }}" @selected(old('repayment_frequency', 'monthly') === $frequency)>{{ str($frequency)->headline() }}</option>@endforeach</select></label>
        <label class="text-sm font-medium">Purpose<input name="purpose" value="{{ old('purpose') }}" class="mt-1 w-full rounded border-slate-300" required></label>
        <label class="text-sm font-medium">Source of repayment<input name="source_of_repayment" value="{{ old('source_of_repayment') }}" class="mt-1 w-full rounded border-slate-300"></label>
        <label class="text-sm font-medium">Risk score<input name="risk_score" value="{{ old('risk_score') }}" type="number" step="0.01" class="mt-1 w-full rounded border-slate-300"></label>
        <label class="text-sm font-medium">Credit score<input name="credit_score" value="{{ old('credit_score') }}" type="number" step="0.01" class="mt-1 w-full rounded border-slate-300"></label>
        <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Business details<textarea name="business_details" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('business_details') }}</textarea></label>
        <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Existing loans<textarea name="existing_loans" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('existing_loans') }}</textarea></label>
        <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Collateral details<textarea name="collateral_details" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('collateral_details') }}</textarea></label>
        <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Credit officer comments<textarea name="credit_officer_comments" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('credit_officer_comments') }}</textarea></label>
    </div>
    @if($errors->any())
        <div class="mt-5 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif
    <button class="mt-6 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Submit application</button>
</form>
@endsection
