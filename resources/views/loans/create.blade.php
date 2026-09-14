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
<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <div class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-700">Loans</div>
        <h1 class="mt-1 text-2xl font-semibold text-slate-950">New loan application</h1>
        <p class="mt-1 text-sm text-slate-500">Capture the facility details, assessment notes, and proposed repayment schedule.</p>
    </div>
    <a href="{{ route('loans.index') }}" class="df-action-secondary shrink-0">Back to loans</a>
</div>
<form method="POST" action="{{ route('loans.store') }}" class="grid gap-5" data-loan-schedule-preview data-preview-url="{{ route('loans.schedule-preview') }}">
    @csrf
    <section class="rounded border bg-white p-4 sm:p-5">
        <div class="mb-4 border-b border-slate-200 pb-3">
            <h2 class="font-semibold text-slate-950">Loan setup</h2>
            <p class="mt-1 text-xs text-slate-500">Required facility, client, and repayment information.</p>
        </div>
        <div class="grid gap-x-4 gap-y-3 md:grid-cols-2 xl:grid-cols-4">
            <label class="text-sm font-medium md:col-span-2">Client {!! $requiredBadge !!}<select name="client_id" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id', $selectedClient ?? null) == $client->id)>{{ $client->client_number }} - {{ $client->full_name }}{{ $client->client_type === 'individual' && (blank($client->national_id) || blank($client->date_of_birth) || blank($client->gender)) ? ' · Bio incomplete' : '' }}</option>@endforeach</select></label>
            <label class="text-sm font-medium">Product {!! $requiredBadge !!}<select name="loan_product_id" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required>@foreach($products as $product)<option value="{{ $product->id }}" data-interest-rate="{{ $product->annual_interest_rate }}" data-repayment-frequency="{{ $product->repayment_frequency }}" @selected($selectedProduct?->id === $product->id)>{{ $product->name }} · {{ $product->annual_interest_rate }}%</option>@endforeach</select></label>
            <label class="text-sm font-medium">Loan type {!! $requiredBadge !!}<select name="loan_type" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required><option value="standard" @selected(old('loan_type', 'standard') === 'standard')>Officer loan</option>@if($canAssignOfficer)<option value="commercial" @selected(old('loan_type') === 'commercial')>Commercial loan</option>@endif</select></label>
            @if($canAssignOfficer)
                <label class="text-sm font-medium md:col-span-2 xl:col-span-1">Assigned officer {!! $commercialRequiredBadge !!}<select name="assigned_officer_id" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2"><option value="">Use me as officer</option>@foreach($assignableOfficers as $officer)<option value="{{ $officer->id }}" @selected(old('assigned_officer_id') == $officer->id)>{{ $officer->name }} · {{ $officer->role?->name }}</option>@endforeach</select></label>
            @endif
            <label class="text-sm font-medium">Application date {!! $requiredBadge !!}<input name="application_date" value="{{ old('application_date', today()->toDateString()) }}" type="date" max="{{ today()->toDateString() }}" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required></label>
            <label class="text-sm font-medium">Requested amount {!! $requiredBadge !!}<input name="requested_amount" value="{{ old('requested_amount') }}" type="number" min="100" step="100" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required></label>
            <div class="text-sm font-medium">Package interest per annum
                <div class="mt-1 flex min-h-10 items-center rounded border border-slate-200 bg-slate-50 px-3 py-2 font-semibold text-slate-950" data-package-interest-rate>{{ number_format((float) ($selectedProduct?->annual_interest_rate ?? 0), 4) }}%</div>
            </div>
            <label class="text-sm font-medium">Repayment period <span data-period-unit>weeks</span> {!! $requiredBadge !!}<input name="term_periods" value="{{ $defaultTermPeriods }}" type="number" min="1" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required></label>
            <label class="text-sm font-medium">Schedule {!! $requiredBadge !!}<select name="repayment_frequency" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" required>@foreach(['monthly' => 'Monthly (months)', 'weekly' => 'Weekly (weeks)', 'daily' => 'Daily (days)'] as $frequency => $label)<option value="{{ $frequency }}" @selected($defaultRepaymentFrequency === $frequency)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-medium md:col-span-2">Purpose<input name="purpose" value="{{ old('purpose') }}" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" placeholder="What the loan will finance"></label>
            <label class="text-sm font-medium md:col-span-2">Source of repayment<input name="source_of_repayment" value="{{ old('source_of_repayment') }}" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2" placeholder="Primary repayment income source"></label>
            <label class="text-sm font-medium">Risk score<input name="risk_score" value="{{ old('risk_score') }}" type="number" step="0.01" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2"></label>
            <label class="text-sm font-medium">Credit score<input name="credit_score" value="{{ old('credit_score') }}" type="number" step="0.01" class="mt-1 min-h-10 w-full rounded border-slate-300 py-2"></label>
        </div>
    </section>

    @if(auth()->user()->canPerform('disburse-loans'))
        <section class="rounded border bg-white p-4 sm:p-5">
            <h2 class="font-semibold text-slate-950">Special application fee</h2>
            <p class="mt-1 text-sm text-slate-500">Leave blank for the normal fee, enter a custom amount, or enter 0 to waive it for this loan.</p>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label class="text-sm font-medium">Application fee (UGX)<input name="application_fee_override" type="number" min="0" step="100" value="{{ old('application_fee_override') }}" class="mt-1 w-full rounded border-slate-300"></label>
                <label class="text-sm font-medium">Reason for custom fee or waiver<input name="application_fee_override_reason" maxlength="1000" value="{{ old('application_fee_override_reason') }}" class="mt-1 w-full rounded border-slate-300"></label>
            </div>
        </section>
    @endif

    <section class="rounded border bg-white p-4 sm:p-5">
        <div class="mb-4 border-b border-slate-200 pb-3">
            <h2 class="font-semibold text-slate-950">Assessment notes</h2>
            <p class="mt-1 text-xs text-slate-500">Supporting information for review, underwriting, and approval.</p>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="text-sm font-medium">Business details<textarea name="business_details" rows="3" class="mt-1 min-h-24 w-full rounded border-slate-300" placeholder="Business activity, trading history, and cash flow">{{ old('business_details') }}</textarea></label>
            <label class="text-sm font-medium">Existing loans<textarea name="existing_loans" rows="3" class="mt-1 min-h-24 w-full rounded border-slate-300" placeholder="Current lenders, balances, and installments">{{ old('existing_loans') }}</textarea></label>
            <label class="text-sm font-medium">Collateral details<textarea name="collateral_details" rows="3" class="mt-1 min-h-24 w-full rounded border-slate-300" placeholder="Proposed security and estimated value">{{ old('collateral_details') }}</textarea></label>
            <label class="text-sm font-medium">Credit officer comments<textarea name="credit_officer_comments" rows="3" class="mt-1 min-h-24 w-full rounded border-slate-300" placeholder="Assessment observations and recommendation">{{ old('credit_officer_comments') }}</textarea></label>
        </div>
    </section>

    <section class="rounded border bg-white p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Payment schedule preview</h2>
                <p class="mt-1 text-xs text-slate-500">Review the proposed installments before creating the application.</p>
            </div>
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
    <div class="sticky bottom-3 z-10 flex flex-col-reverse gap-2 rounded border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur sm:flex-row sm:justify-end">
        <a href="{{ route('loans.index') }}" class="df-action-secondary">Cancel</a>
        <button class="df-action-primary">Create application</button>
    </div>
</form>
@endsection
