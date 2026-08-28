@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <a href="{{ route('organizations.index') }}" class="text-sm font-semibold text-brand-700">← Organizations</a>
        <h1 class="mt-2 text-2xl font-semibold text-slate-950">Financial defaults</h1>
        <p class="text-sm text-slate-500">{{ $organization->name }} · {{ $organization->slug }}</p>
    </div>
    <div class="flex gap-2 text-sm">
        <span class="df-badge">{{ $loanProductCount }} loan products</span>
        <span class="df-badge">{{ $chartAccountCount }} chart accounts</span>
    </div>
</div>

@if($errors->has('financial_defaults'))
    <div class="mb-5 rounded border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
        {{ $errors->first('financial_defaults') }}
    </div>
@endif

<form method="POST" action="{{ route('organizations.financial-defaults.store', $organization) }}" class="grid gap-5">
    @csrf

    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Loan product templates</h2>
            <p class="text-sm text-slate-500">Choose several products to add. Existing products and tenant customizations are never overwritten.</p>
        </div>
        <div class="grid gap-3 p-5 lg:grid-cols-2">
            @foreach($loanProductTemplates as $template)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 hover:border-brand-300 hover:bg-brand-50/40">
                    <input type="checkbox" name="loan_product_template_ids[]" value="{{ $template->id }}" class="mt-1 rounded border-slate-300 text-brand-700" @checked(in_array($template->id, old('loan_product_template_ids', [])))>
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-slate-950">{{ $template->name }}</span>
                            <span class="df-badge {{ $template->is_assigned ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : '' }}">{{ $template->is_assigned ? 'Already assigned' : 'Available' }}</span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">{{ $template->code }} · UGX {{ number_format($template->min_amount) }}–{{ number_format($template->max_amount) }} · {{ $template->annual_interest_rate }}%</span>
                    </span>
                </label>
            @endforeach
        </div>
    </section>

    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Chart-account templates</h2>
            <p class="text-sm text-slate-500">Organization accounts are added once. Branch templates are added to all {{ $branches->count() }} existing branch{{ $branches->count() === 1 ? '' : 'es' }}.</p>
        </div>
        <div class="grid gap-3 p-5 lg:grid-cols-2">
            @foreach($chartAccountTemplates as $template)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 hover:border-brand-300 hover:bg-brand-50/40">
                    <input type="checkbox" name="chart_account_template_ids[]" value="{{ $template->id }}" class="mt-1 rounded border-slate-300 text-brand-700" @checked(in_array($template->id, old('chart_account_template_ids', [])))>
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-slate-950">{{ $template->code }} · {{ $template->name }}</span>
                            <span class="df-badge">{{ str($template->scope)->headline() }}</span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">
                            {{ str($template->type)->headline() }} · {{ str($template->normal_balance)->headline() }}
                            · {{ $template->assigned_instances }}/{{ $template->target_instances }} assigned
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
    </section>

    @error('loan_product_template_ids')<div class="text-sm font-semibold text-red-700">{{ $message }}</div>@enderror
    @error('chart_account_template_ids')<div class="text-sm font-semibold text-red-700">{{ $message }}</div>@enderror

    <div class="flex justify-end">
        <button class="df-action-primary">Assign selected defaults</button>
    </div>
</form>

<section class="mt-8 rounded-xl border border-red-200 bg-red-50/60 p-5">
    <h2 class="font-semibold text-red-900">Reset to system defaults</h2>
    <p class="mt-1 text-sm text-red-800">Reset restores every standard record and removes unused custom records in the selected category. It is blocked if any record has loan, accounting, hierarchy, or merge history.</p>

    <div class="mt-5 grid gap-4 lg:grid-cols-2">
        @foreach(['loan_products' => 'Loan products', 'chart_accounts' => 'Chart accounts'] as $resource => $label)
            <form method="POST" action="{{ route('organizations.financial-defaults.destroy', $organization) }}" class="rounded-lg border border-red-200 bg-white p-4" data-confirm-title="Reset {{ strtolower($label) }}" data-confirm-message="This removes unused custom {{ strtolower($label) }} and restores the complete system catalog for {{ $organization->name }}. Referenced financial records will block the reset." data-confirm-label="Reset {{ $label }}" data-confirm-tone="danger">
                @csrf
                @method('DELETE')
                <input type="hidden" name="resource" value="{{ $resource }}">
                <div class="font-semibold text-slate-950">{{ $label }}</div>
                <label class="mt-3 block text-sm font-semibold text-slate-700">
                    Type <span class="font-mono text-red-700">RESET {{ $organization->slug }}</span>
                    <input name="confirmation" class="mt-1" autocomplete="off" required>
                </label>
                <input type="hidden" data-confirm-summary-label="Organization" data-confirm-summary-value="{{ $organization->name }}">
                <input type="hidden" data-confirm-summary-label="Category" data-confirm-summary-value="{{ $label }}">
                <button class="mt-4 rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Reset {{ strtolower($label) }}</button>
            </form>
        @endforeach
    </div>
    @error('confirmation')<div class="mt-3 text-sm font-semibold text-red-700">{{ $message }}</div>@enderror
</section>
@endsection
