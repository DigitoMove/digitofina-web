@extends('layouts.app')

@section('content')
@php
    $reportCount = $groups->flatten(1)->count();
    $groupDescriptions = [
        'Executive Financial Pack' => 'Board, management, and accountant review statements.',
        'General Ledger & Controls' => 'Posting audit, account analysis, and accounting controls.',
        'Treasury, Cash & Reconciliation' => 'Cash position, teller accountability, and branch reconciliation.',
        'Credit Portfolio & Risk' => 'Loan book, disbursement, arrears, PAR, recovery, and profitability.',
        'Deposits & Member Funds' => 'Savings, current accounts, shares, and term deposit liability reports.',
        'Investments & Managed Funds' => 'Investment balances, returns, maturities, and officer performance.',
        'Client & Relationship Management' => 'Client, officer, and relationship activity registers.',
        'Operational Registers' => 'Supporting operational registers and exception lists.',
    ];
    $groupAccents = [
        'Executive Financial Pack' => 'border-slate-300 bg-slate-50 text-slate-800',
        'General Ledger & Controls' => 'border-sky-200 bg-sky-50 text-sky-800',
        'Treasury, Cash & Reconciliation' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'Credit Portfolio & Risk' => 'border-blue-200 bg-blue-50 text-blue-800',
        'Deposits & Member Funds' => 'border-teal-200 bg-teal-50 text-teal-800',
        'Investments & Managed Funds' => 'border-indigo-200 bg-indigo-50 text-indigo-800',
        'Client & Relationship Management' => 'border-amber-200 bg-amber-50 text-amber-800',
        'Operational Registers' => 'border-zinc-200 bg-zinc-50 text-zinc-800',
    ];
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Financial Reports</h1>
        <p class="df-muted">Professional report packs for accounting, treasury, credit risk, deposits, investments, and branch performance.</p>
    </div>
    <div class="grid gap-2 text-right text-sm">
        <div class="font-semibold text-slate-950">{{ number_format($reportCount) }} reports available</div>
        <div class="text-slate-500">Default period: {{ \Carbon\Carbon::parse($defaultFrom)->format('d M Y') }} to {{ \Carbon\Carbon::parse($defaultTo)->format('d M Y') }}</div>
    </div>
</div>

@if($priorityReports->isNotEmpty())
    <section class="mb-6">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Management Pack</h2>
                <p class="text-sm text-slate-500">Start here for the reports most finance teams review daily, weekly, or monthly.</p>
            </div>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach($priorityReports as $report)
                <button
                    type="button"
                    class="rounded border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-brand-300 hover:bg-brand-50/40 hover:shadow"
                    data-report-open
                    data-report-title="{{ $report['title'] }}"
                    data-report-description="{{ $report['description'] }}"
                    data-report-url="{{ route('reports.show', $report['slug']) }}"
                    data-report-dates="{{ $report['usesDates'] ? '1' : '0' }}"
                    data-report-detail="{{ $report['hasDetail'] ? '1' : '0' }}"
                    data-report-profitability="{{ $report['category'] === 'Profitability' ? '1' : '0' }}"
                    data-report-interest-basis="{{ $report['payload']['interest_basis'] ?? 'collected' }}"
                >
                    <span class="block text-xs font-semibold uppercase text-slate-500">{{ $report['category'] }}</span>
                    <span class="mt-1 block font-semibold text-slate-950">{{ $report['title'] }}</span>
                    <span class="mt-2 block text-sm leading-5 text-slate-600">{{ $report['description'] }}</span>
                    <span class="mt-3 inline-flex text-sm font-semibold text-brand-700">Generate report</span>
                </button>
            @endforeach
        </div>
    </section>
@endif

<div class="grid gap-5 xl:grid-cols-2">
    @foreach($groups as $group => $reports)
        <section class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="inline-flex rounded border px-2.5 py-1 text-xs font-semibold {{ $groupAccents[$group] ?? 'border-slate-200 bg-slate-50 text-slate-700' }}">
                            {{ $group }}
                        </div>
                        <p class="mt-2 text-sm text-slate-500">{{ $groupDescriptions[$group] ?? 'Reports and registers for review.' }}</p>
                    </div>
                    <div class="rounded bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $reports->count() }} reports</div>
                </div>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($reports as $report)
                    <button
                        type="button"
                        class="grid w-full gap-2 px-5 py-4 text-left transition hover:bg-slate-50"
                        data-report-open
                        data-report-title="{{ $report['title'] }}"
                        data-report-description="{{ $report['description'] }}"
                        data-report-url="{{ route('reports.show', $report['slug']) }}"
                        data-report-dates="{{ $report['usesDates'] ? '1' : '0' }}"
                        data-report-detail="{{ $report['hasDetail'] ? '1' : '0' }}"
                        data-report-profitability="{{ $report['category'] === 'Profitability' ? '1' : '0' }}"
                        data-report-interest-basis="{{ $report['payload']['interest_basis'] ?? 'collected' }}"
                    >
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-slate-950">{{ $report['title'] }}</span>
                            <span class="text-sm font-semibold text-brand-700">Generate</span>
                        </span>
                        <span class="text-sm leading-5 text-slate-600">{{ $report['description'] }}</span>
                        <span class="flex flex-wrap gap-2 text-xs font-semibold text-slate-500">
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $report['category'] }}</span>
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $report['usesDates'] ? 'Period report' : 'Point-in-time' }}</span>
                            <span class="rounded bg-slate-100 px-2 py-1">{{ $report['hasDetail'] ? 'Brief or detailed' : 'Register' }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

<div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4" data-report-modal>
    <form method="GET" class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl" data-report-form>
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-950" data-report-modal-title>Generate Report</h2>
                <p class="mt-1 text-sm text-slate-500" data-report-modal-description>Choose the filters, then generate the report.</p>
            </div>
            <button type="button" class="rounded border px-3 py-1 text-sm font-semibold" data-report-close>Close</button>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2" data-report-date-fields>
            <label class="text-sm font-medium text-slate-700">From
                <input name="from" type="date" value="{{ $defaultFrom }}" class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="text-sm font-medium text-slate-700">To
                <input name="to" type="date" value="{{ $defaultTo }}" class="mt-1 w-full rounded border-slate-300">
            </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <label class="text-sm font-medium text-slate-700" data-report-detail-field>Report type
                <select name="detail" class="mt-1 w-full rounded border-slate-300">
                    <option value="detailed">Detailed</option>
                    <option value="brief">Brief</option>
                </select>
            </label>
            @if($canChooseBranch)
                <label class="text-sm font-medium text-slate-700">Branch
                    <select name="branch_id" class="mt-1 w-full rounded border-slate-300">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        <div class="mt-4" data-report-interest-field hidden>
            <label class="text-sm font-medium text-slate-700">Interest basis
                <select name="interest_basis" class="mt-1 w-full rounded border-slate-300" disabled>
                    <option value="collected">Collected interest only</option>
                    <option value="projected">Collected plus projected interest</option>
                </select>
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button type="button" class="rounded border px-4 py-2 text-sm font-semibold" data-report-close>Cancel</button>
            <button class="df-action-primary">Generate</button>
        </div>
    </form>
</div>
@endsection
