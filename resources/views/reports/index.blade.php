@extends('layouts.app')

@section('content')
@php
    $reportCount = $groups->flatten(1)->count();
    $prioritySlugs = $priorityReports->pluck('slug');
    $categoryDescriptions = [
        'General Reports' => 'Simplified management reports for income, expenses, money movement, and profit.',
        'Posted Transactions' => 'Cash, system, user, teller, and non-cash transaction histories.',
        'Treasury' => 'Cash position, teller accountability, and branch reconciliation.',
        'Clients' => 'Client and relationship activity registers.',
        'Accounting' => 'General ledger, account analysis, and financial statements.',
        'Ledger Accounts' => 'Cashier-accessible ledger account balances, summaries, and transaction histories.',
        'Profitability' => 'Income, fees, interest, and branch performance.',
        'Savings' => 'Savings account activity, balances, holds, and standing orders.',
        'Shares' => 'Member share accounts, balances, and transactions.',
        'Loan Application' => 'Application, appraisal, approval, and rejection registers.',
        'Loan Accounts' => 'Loan book, repayment, arrears, recovery, and risk reports.',
        'Term Deposits' => 'Funding, maturity, liquidation, and term-deposit registers.',
        'Current Accounts' => 'Current account registers and balances.',
        'Investments' => 'Investment balances, movements, returns, and performance.',
    ];
    $categoryAccents = [
        'General Reports' => 'border-brand-200 bg-brand-50 text-brand-950',
        'Posted Transactions' => 'border-amber-200 bg-amber-50 text-amber-900',
        'Treasury' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'Clients' => 'border-orange-200 bg-orange-50 text-orange-900',
        'Accounting' => 'border-sky-200 bg-sky-50 text-sky-900',
        'Ledger Accounts' => 'border-sky-200 bg-sky-50 text-sky-900',
        'Profitability' => 'border-violet-200 bg-violet-50 text-violet-900',
        'Savings' => 'border-slate-200 bg-slate-50 text-slate-900',
        'Shares' => 'border-yellow-200 bg-yellow-50 text-yellow-900',
        'Loan Application' => 'border-cyan-200 bg-cyan-50 text-cyan-900',
        'Loan Accounts' => 'border-blue-200 bg-blue-50 text-blue-900',
        'Term Deposits' => 'border-green-200 bg-green-50 text-green-900',
        'Current Accounts' => 'border-zinc-200 bg-zinc-50 text-zinc-900',
        'Investments' => 'border-indigo-200 bg-indigo-50 text-indigo-900',
    ];
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <p class="text-sm font-medium text-slate-500">Finance & Operations</p>
        <h1 class="df-title">Reports</h1>
        <p class="df-muted">Choose a report, set its filters, and generate it.</p>
    </div>
    <div class="rounded border border-slate-200 bg-white px-4 py-3 text-right shadow-sm">
        <div class="font-semibold text-slate-950">{{ number_format($reportCount) }} reports available</div>
        <div class="text-xs text-slate-500">Default period: {{ \Carbon\Carbon::parse($defaultFrom)->format('d M Y') }} to {{ \Carbon\Carbon::parse($defaultTo)->format('d M Y') }}</div>
    </div>
</div>

<div class="columns-1 gap-5 md:columns-2 xl:columns-3 2xl:columns-4">
    @foreach($groups as $category => $reports)
        <section class="mb-5 break-inside-avoid overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
            <div class="border-b px-4 py-3 text-center {{ $categoryAccents[$category] ?? 'border-slate-200 bg-slate-50 text-slate-900' }}">
                <div class="font-semibold">{{ $category }}</div>
                <p class="mt-1 text-xs opacity-75">{{ $categoryDescriptions[$category] ?? 'Operational reports and registers.' }}</p>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($reports as $report)
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm text-slate-700 transition hover:bg-brand-50 hover:text-brand-900"
                        data-report-open
                        data-report-title="{{ $report['title'] }}"
                        data-report-description="{{ $report['description'] }}"
                        data-report-url="{{ route('reports.show', $report['slug']) }}"
                        data-report-dates="{{ $report['usesDates'] ? '1' : '0' }}"
                        data-report-arrears-rate="{{ $report['slug'] === 'arrears-rate' ? '1' : '0' }}"
                        data-report-detail="{{ $report['hasDetail'] ? '1' : '0' }}"
                        data-report-profitability="{{ $report['category'] === 'Profitability' ? '1' : '0' }}"
                        data-report-interest-basis="{{ $report['payload']['interest_basis'] ?? 'collected' }}"
                        data-report-from="{{ ($report['payload']['default_period'] ?? null) === 'full_month' ? now()->startOfMonth()->toDateString() : $defaultFrom }}"
                        data-report-to="{{ ($report['payload']['default_period'] ?? null) === 'full_month' ? now()->endOfMonth()->toDateString() : $defaultTo }}"
                        data-report-ledger="{{ in_array($report['kind'], ['journal_entries', 'non_cash_transactions', 'daily_ledger_summary'], true) ? '1' : '0' }}"
                        data-report-ledger-mode="{{ $report['kind'] === 'daily_ledger_summary' ? 'multiple' : 'single' }}"
                    >
                        <span>{{ $report['title'] }}</span>
                        @if($prioritySlugs->contains($report['slug']))
                            <span class="shrink-0 rounded bg-brand-50 px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase text-brand-700">Key</span>
                        @else
                            <span class="shrink-0 text-brand-500">›</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

<div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4" data-report-modal>
    <form method="GET" class="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-lg bg-white p-5 shadow-xl" data-report-form>
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

        <label class="mt-3 hidden items-center gap-2 text-sm font-medium text-slate-700" data-report-lifetime-field>
            <input name="lifetime" type="checkbox" value="1" class="rounded border-slate-300">
            Use the lifetime of the business
            <span class="font-normal text-slate-500">(include every disbursed loan)</span>
        </label>

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
            <label class="text-sm font-medium text-slate-700" data-report-posted-by-field>Posted by
                <select name="posted_by_user_id" class="mt-1 w-full rounded border-slate-300">
                    <option value="">All staff users</option>
                    @foreach($staffUsers as $staffUser)
                        <option value="{{ $staffUser->id }}">{{ $staffUser->name }}{{ $staffUser->role ? ' · '.$staffUser->role->name : '' }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-4" data-report-ledger-field hidden>
            <label class="text-sm font-medium text-slate-700" data-report-ledger-single-field>Ledger account
                <select name="chart_account_id" class="mt-1 w-full rounded border-slate-300" disabled>
                    <option value="">All ledger accounts</option>
                    @foreach($chartAccounts as $chartAccount)
                        <option value="{{ $chartAccount->id }}">{{ $chartAccount->code }} · {{ $chartAccount->name }}{{ $chartAccount->branch ? ' · '.$chartAccount->branch->name : '' }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-slate-500">Use this to report on a particular merchant-code, mobile-money, or general ledger account.</span>
            </label>
            <label class="hidden text-sm font-medium text-slate-700" data-report-ledger-multiple-field>Ledger accounts
                <select name="chart_account_ids[]" multiple size="8" class="mt-1 w-full rounded border-slate-300" disabled>
                    @foreach($chartAccounts as $chartAccount)
                        <option value="{{ $chartAccount->id }}">{{ $chartAccount->code }} · {{ $chartAccount->name }}{{ $chartAccount->branch ? ' · '.$chartAccount->branch->name : '' }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-slate-500">Select the merchant, mobile-money, bank, or other ledgers to summarize together. Leave empty to include all active ledgers in scope.</span>
            </label>
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
