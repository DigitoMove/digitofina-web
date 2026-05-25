@extends('layouts.app')

@section('content')
@php
    $categoryStyles = [
        'Posted Transactions' => ['bg-amber-50 text-amber-800 border-amber-200', 'bg-amber-100 text-amber-800'],
        'Treasury' => ['bg-emerald-50 text-emerald-800 border-emerald-200', 'bg-emerald-100 text-emerald-800'],
        'Clients' => ['bg-yellow-50 text-yellow-800 border-yellow-200', 'bg-yellow-100 text-yellow-800'],
        'Accounting' => ['bg-sky-50 text-sky-800 border-sky-200', 'bg-sky-100 text-sky-800'],
        'Profitability' => ['bg-emerald-50 text-emerald-800 border-emerald-200', 'bg-emerald-100 text-emerald-800'],
        'Savings' => ['bg-slate-50 text-slate-800 border-slate-200', 'bg-slate-100 text-slate-800'],
        'Shares' => ['bg-orange-50 text-orange-800 border-orange-200', 'bg-orange-100 text-orange-800'],
        'Loan Application' => ['bg-cyan-50 text-cyan-800 border-cyan-200', 'bg-cyan-100 text-cyan-800'],
        'Loan Accounts' => ['bg-blue-50 text-blue-800 border-blue-200', 'bg-blue-100 text-blue-800'],
        'Term Deposits' => ['bg-lime-50 text-lime-800 border-lime-200', 'bg-lime-100 text-lime-800'],
        'Current Accounts' => ['bg-zinc-50 text-zinc-800 border-zinc-200', 'bg-zinc-100 text-zinc-800'],
    ];
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Reports</h1>
        <p class="df-muted">Generate operational, savings, loan, treasury, accounting, and portfolio reports.</p>
    </div>
    <div class="df-soft-panel px-4 py-3 text-sm text-slate-600">{{ $groups->flatten(1)->count() }} reports available</div>
</div>

<div class="columns-1 gap-5 lg:columns-2 2xl:columns-3">
    @foreach($groups as $category => $reports)
        @php([$panelClass, $headingClass] = $categoryStyles[$category] ?? ['bg-white text-slate-800 border-slate-200', 'bg-slate-100 text-slate-800'])
        <section class="mb-5 break-inside-avoid overflow-hidden rounded border {{ $panelClass }}">
            <div class="flex items-center justify-between gap-3 border-b border-current/10 px-5 py-4 {{ $headingClass }}">
                <h2 class="font-semibold">{{ $category }}</h2>
                <span class="rounded bg-white/70 px-2 py-1 text-xs font-semibold">{{ $reports->count() }}</span>
            </div>
            <div class="divide-y divide-slate-200 bg-white">
                @foreach($reports as $report)
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-5 py-3 text-left text-sm text-slate-700 transition hover:bg-slate-50 hover:text-slate-950"
                        data-report-open
                        data-report-title="{{ $report['title'] }}"
                        data-report-url="{{ route('reports.show', $report['slug']) }}"
                        data-report-dates="{{ $report['usesDates'] ? '1' : '0' }}"
                        data-report-detail="{{ $report['hasDetail'] ? '1' : '0' }}"
                        data-report-profitability="{{ $report['category'] === 'Profitability' ? '1' : '0' }}"
                        data-report-interest-basis="{{ $report['payload']['interest_basis'] ?? 'collected' }}"
                    >
                        <span>{{ $report['title'] }}</span>
                        <span class="text-xs font-semibold text-slate-400">Generate</span>
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
                <p class="mt-1 text-sm text-slate-500">Choose the filters, then generate the report.</p>
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
