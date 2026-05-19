@extends('layouts.app')
@section('content')
@php
    $weeklyProduct = $products->first(fn ($product) => strtoupper((string) $product->code) === 'WEEKLY20')
        ?? $products->first(fn ($product) => str_contains(strtoupper((string) ($product->code.' '.$product->name)), 'WEEKLY20'))
        ?? $products->first(fn ($product) => str_contains(strtoupper((string) ($product->code.' '.$product->name)), 'WEEKLY'))
        ?? $products->first();
    $selectedProduct = old('loan_product_id')
        ? $products->firstWhere('id', (int) old('loan_product_id'))
        : $weeklyProduct;
    $defaultRepaymentFrequency = old('repayment_frequency', 'weekly');
    $defaultTermPeriods = old('term_periods', 4);
    $requiredBadge = '<span class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-red-700">Required</span>';
    $commercialRequiredBadge = '<span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">Required for commercial</span>';
@endphp
<div class="mb-5 flex items-center justify-between gap-3">
    <h1 class="text-2xl font-semibold">Loan Application</h1>
    <a href="{{ route('loans.index') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back</a>
</div>
<form method="POST" action="{{ route('loans.store') }}" class="grid gap-5" data-loan-schedule-preview data-preview-url="{{ route('loans.schedule-preview') }}">
    @csrf
    <section class="rounded border bg-white p-5">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <label class="text-sm font-medium">Client {!! $requiredBadge !!}<select name="client_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id', $selectedClient ?? null) == $client->id)>{{ $client->client_number }} - {{ $client->full_name }}</option>@endforeach</select></label>
            <label class="text-sm font-medium">Product {!! $requiredBadge !!}<select name="loan_product_id" class="mt-1 w-full rounded border-slate-300" required>@foreach($products as $product)<option value="{{ $product->id }}" data-interest-rate="{{ $product->annual_interest_rate }}" data-repayment-frequency="{{ $product->repayment_frequency }}" @selected($selectedProduct?->id === $product->id)>{{ $product->name }} · {{ $product->annual_interest_rate }}%</option>@endforeach</select></label>
            <label class="text-sm font-medium">Loan type {!! $requiredBadge !!}<select name="loan_type" class="mt-1 w-full rounded border-slate-300" required><option value="standard" @selected(old('loan_type', 'standard') === 'standard')>Officer loan</option>@if($canAssignOfficer)<option value="commercial" @selected(old('loan_type') === 'commercial')>Commercial loan</option>@endif</select></label>
            @if($canAssignOfficer)
                <label class="text-sm font-medium">Assigned officer {!! $commercialRequiredBadge !!}<select name="assigned_officer_id" class="mt-1 w-full rounded border-slate-300"><option value="">Use me as officer</option>@foreach($assignableOfficers as $officer)<option value="{{ $officer->id }}" @selected(old('assigned_officer_id') == $officer->id)>{{ $officer->name }} · {{ $officer->role?->name }}</option>@endforeach</select></label>
            @endif
            <label class="text-sm font-medium">Application date {!! $requiredBadge !!}<input name="application_date" value="{{ old('application_date', today()->toDateString()) }}" type="date" max="{{ today()->toDateString() }}" class="mt-1 w-full rounded border-slate-300" required></label>
            <label class="text-sm font-medium">Requested amount {!! $requiredBadge !!}<input name="requested_amount" value="{{ old('requested_amount') }}" type="number" min="100" step="100" class="mt-1 w-full rounded border-slate-300" required></label>
            <div class="text-sm font-medium">Package interest per annum
                <div class="mt-1 rounded border border-slate-200 bg-slate-50 px-3 py-2 font-semibold text-slate-950" data-package-interest-rate>{{ number_format((float) ($selectedProduct?->annual_interest_rate ?? 0), 4) }}%</div>
            </div>
            <label class="text-sm font-medium">Repayment period <span data-period-unit>weeks</span> {!! $requiredBadge !!}<input name="term_periods" value="{{ $defaultTermPeriods }}" type="number" min="1" class="mt-1 w-full rounded border-slate-300" required></label>
            <label class="text-sm font-medium">Schedule {!! $requiredBadge !!}<select name="repayment_frequency" class="mt-1 w-full rounded border-slate-300" required>@foreach(['monthly' => 'Monthly (months)', 'weekly' => 'Weekly (weeks)', 'daily' => 'Daily (days)'] as $frequency => $label)<option value="{{ $frequency }}" @selected($defaultRepaymentFrequency === $frequency)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-medium">Purpose<input name="purpose" value="{{ old('purpose') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium">Source of repayment<input name="source_of_repayment" value="{{ old('source_of_repayment') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium">Risk score<input name="risk_score" value="{{ old('risk_score') }}" type="number" step="0.01" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium">Credit score<input name="credit_score" value="{{ old('credit_score') }}" type="number" step="0.01" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Business details<textarea name="business_details" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('business_details') }}</textarea></label>
            <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Existing loans<textarea name="existing_loans" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('existing_loans') }}</textarea></label>
            <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Collateral details<textarea name="collateral_details" rows="2" class="mt-1 w-full rounded border-slate-300">{{ old('collateral_details') }}</textarea></label>
            <label class="text-sm font-medium md:col-span-2 xl:col-span-3">Credit officer comments<textarea name="credit_officer_comments" rows="3" class="mt-1 w-full rounded border-slate-300">{{ old('credit_officer_comments') }}</textarea></label>
        </div>
    </section>

    <section class="rounded border bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-slate-950">Payment Schedule Preview</h2>
            <button type="button" class="df-action-secondary" data-preview-schedule>Preview Schedule</button>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Installments</div><div class="mt-2 text-xl font-semibold text-slate-950" data-preview-installments>0</div></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Principal</div><div class="mt-2 text-xl font-semibold text-slate-950" data-preview-principal>0</div></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Interest</div><div class="mt-2 text-xl font-semibold text-slate-950" data-preview-interest>0</div></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total</div><div class="mt-2 text-xl font-semibold text-slate-950" data-preview-total>0</div></div>
        </div>
        <div class="mt-4 rounded-lg border border-slate-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50"><tr><th class="p-2">#</th><th>Due date</th><th>Principal</th><th>Interest</th><th>Total</th><th>Balance</th></tr></thead>
                <tbody data-preview-rows><tr><td colspan="6" class="p-4 text-center text-slate-500">No preview yet.</td></tr></tbody>
            </table>
        </div>
        <div class="mt-3 text-sm text-red-700" data-preview-error hidden></div>
    </section>
    @if($errors->any())
        <div class="mt-5 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif
    <button class="mt-6 rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Create application</button>
</form>
@endsection
