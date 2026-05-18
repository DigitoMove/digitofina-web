@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Dashboard</h1>
        <p class="text-sm text-slate-500">Branch operations, cash position, approvals, and portfolio pulse.</p>
    </div>
    <a href="{{ route('teller.index') }}" class="df-action-primary">Open Teller Drawer</a>
</div>

@php
    $cards = [
        ['Total clients', $totalClients, 'bg-brand-50 text-brand-800', 'from-brand-500 to-brand-600', [38, 58, 44, 68, 74], route('clients.index')],
        ['Active accounts', $activeAccounts, 'bg-emerald-50 text-emerald-700', 'from-emerald-500 to-emerald-600', [46, 52, 63, 57, 78], route('accounts.index')],
        ['Loan applications', $loanApplications, 'bg-sky-50 text-sky-700', 'from-sky-500 to-blue-600', [30, 45, 62, 58, 75], route('loans.index', ['status_group' => 'applications'])],
        ['Loans in arrears', $arrearsCount, 'bg-red-50 text-red-700', 'from-red-500 to-rose-600', [20, 34, 48, 42, 60], route('arrears.index')],
        ['Due today', $dueTodayCount, 'bg-violet-50 text-violet-700', 'from-violet-500 to-indigo-600', [24, 38, 50, 70, 64], route('due-repayments.index')],
        ['Total savings', number_format($totalSavings, 2).' UGX', 'bg-brand-50 text-brand-800', 'from-brand-500 to-forest-500', [42, 55, 61, 76, 84], route('accounts.index')],
        ['Total loans', number_format($totalLoans, 2).' UGX', 'bg-brand-50 text-brand-800', 'from-brand-600 to-sun-400', [35, 48, 65, 62, 80], route('loans.index')],
        ["Today's deposits", number_format($todayDeposits, 2).' UGX', 'bg-green-50 text-green-700', 'from-green-500 to-emerald-500', [28, 44, 50, 72, 88], route('reports.index')],
        ["Today's withdrawals", number_format($todayWithdrawals, 2).' UGX', 'bg-amber-50 text-amber-700', 'from-amber-400 to-orange-500', [24, 40, 38, 52, 46], route('reports.index')],
        ['Teller drawer balance', number_format($drawerBalance, 2).' UGX', 'bg-sun-50 text-sun-600', 'from-sun-400 to-brand-600', [34, 47, 57, 51, 69], route('teller.index')],
        ['Pending approvals', $pendingApprovals, 'bg-rose-50 text-rose-700', 'from-rose-500 to-pink-500', [18, 26, 44, 32, 54], route('reports.index')],
        ['Branches', $branchCount, 'bg-slate-100 text-slate-700', 'from-slate-500 to-brand-600', [44, 50, 54, 58, 62], route('branches.index')],
        ['Active staff', $staffCount, 'bg-lime-50 text-lime-700', 'from-lime-500 to-emerald-500', [32, 43, 52, 68, 73], route('users.index')],
        ['Audit events today', $auditEventsToday, 'bg-slate-100 text-slate-700', 'from-slate-600 to-slate-800', [20, 42, 30, 56, 48], route('audit-logs.index')],
    ];

    $cashRows = [
        ["Today's deposits", $todayDeposits, 'bg-emerald-500'],
        ["Today's withdrawals", $todayWithdrawals, 'bg-amber-500'],
        ['Drawer balance', $drawerBalance, 'bg-sun-400'],
    ];
    $cashMax = max(1, $todayDeposits, $todayWithdrawals, $drawerBalance);

    $portfolioRows = [
        ['Savings', $totalSavings, 'bg-teal-500'],
        ['Loans', $totalLoans, 'bg-brand-700'],
        ['Drawer cash', $drawerBalance, 'bg-amber-500'],
    ];
    $portfolioTotal = max(1, $totalSavings + $totalLoans + $drawerBalance);
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as [$label, $value, $badgeClass, $barClass, $spark, $href])
        <a href="{{ $href }}" class="df-panel df-hover-lift relative overflow-hidden p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $value }}</div>
                </div>
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl {{ $badgeClass }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M5 19V5m0 14h14M9 16v-5m4 5V8m4 8v-7" />
                    </svg>
                </span>
            </div>
            <div class="mt-5 flex h-12 items-end gap-1.5">
                @foreach ($spark as $point)
                    <div class="flex-1 rounded-t-lg bg-gradient-to-t {{ $barClass }}" style="height: {{ $point }}%"></div>
                @endforeach
            </div>
        </a>
    @endforeach
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[1.2fr_0.8fr]">
    <section class="df-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Repayment Calendar</h2>
                <p class="text-sm text-slate-500">{{ $calendarMonth->format('F Y') }} next repayments distributed by scheduled due date.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canFilterCalendarBranch)
                    <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <select name="calendar_branch_id" class="h-10 rounded-lg border-slate-300 text-sm" onchange="this.form.submit()">
                            <option value="">All branches</option>
                            @foreach($calendarBranches as $branch)
                                <option value="{{ $branch->id }}" @selected((int) $selectedCalendarBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <a href="{{ route('due-repayments.index', array_filter(['branch_id' => $selectedCalendarBranchId])) }}" class="df-action-secondary">Today</a>
            </div>
        </div>
        <div class="mt-5 grid grid-cols-7 overflow-hidden rounded-t-lg bg-slate-800 text-center text-xs font-semibold uppercase tracking-wide text-white">
            @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                <div class="border-r border-white/10 px-2 py-3 last:border-r-0">{{ $weekday }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7 overflow-hidden rounded-b-lg border border-t-0 border-slate-200">
            @foreach($calendarDays as $day)
                <a href="{{ route('due-repayments.index', array_filter(['date' => $day['date']->toDateString(), 'branch_id' => $selectedCalendarBranchId])) }}" class="min-h-24 border-b border-r border-slate-200 p-2 text-left transition hover:bg-brand-50 {{ $day['upcoming_count'] > 0 ? 'bg-brand-600 text-white hover:text-slate-950' : ($day['overdue_count'] > 0 ? 'bg-red-600 text-white hover:text-slate-950' : 'bg-white text-slate-700') }} {{ $day['is_today'] ? 'bg-sky-100 text-slate-950 ring-2 ring-inset ring-brand-600' : '' }} {{ $day['is_current_month'] ? '' : 'opacity-40' }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold">{{ $day['date']->day }}</span>
                        @if($day['date']->isSunday())
                            <span class="rounded bg-white/20 px-1.5 py-0.5 text-[10px] font-semibold">Off</span>
                        @endif
                    </div>
                    @if($day['count'] > 0)
                        <div class="mt-3 rounded bg-cyan-300/80 px-2 py-1 text-xs font-semibold text-slate-950">{{ $day['upcoming_count'] > 0 ? $day['upcoming_count'].' next' : $day['count'].' scheduled' }}</div>
                        <div class="mt-1 text-[11px] {{ $day['is_today'] ? 'text-slate-500' : ($day['open_count'] > 0 ? 'text-white/80' : 'text-slate-500') }}">
                            {{ $day['open_count'] }} open · UGX {{ number_format($day['amount'], 0) }}
                        </div>
                        @if($day['overdue_count'] > 0)
                            <div class="mt-1 rounded bg-white/20 px-2 py-0.5 text-[10px] font-semibold">{{ $day['overdue_count'] }} overdue</div>
                        @elseif($day['open_count'] === 0)
                            <div class="mt-1 rounded bg-white/20 px-2 py-0.5 text-[10px] font-semibold">Cleared</div>
                        @endif
                    @else
                        <div class="mt-3 text-xs text-slate-400">No schedules</div>
                    @endif
                </a>
            @endforeach
        </div>
    </section>

    <section class="df-panel p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Next Repayments</h2>
                <p class="text-sm text-slate-500">Upcoming clients expected to pay.</p>
            </div>
            <div class="text-right">
                <div class="text-2xl font-semibold text-slate-950">{{ $nextRepayments->count() }}</div>
                <div class="text-xs text-slate-500">Upcoming</div>
            </div>
        </div>
        <div class="mt-5 divide-y divide-slate-200">
            @forelse($nextRepayments as $schedule)
                <div class="py-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <a href="{{ route('loans.show', $schedule->loan) }}" class="font-semibold text-slate-950 hover:text-brand-700">{{ $schedule->loan->client->full_name }}</a>
                            <div class="mt-1 text-xs text-slate-500">{{ $schedule->loan->client->phone }}{{ $schedule->loan->client->alternative_phone ? ' / '.$schedule->loan->client->alternative_phone : '' }}</div>
                        </div>
                        <div class="text-right text-sm font-semibold text-slate-950">UGX {{ number_format($schedule->outstanding_amount, 0) }}</div>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span>{{ $schedule->due_date?->format('d M Y') }}</span>
                        <span>{{ $schedule->loan->loan_number }} · Installment #{{ $schedule->installment_number }}</span>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center text-sm text-slate-500">No upcoming repayments are scheduled.</div>
            @endforelse
        </div>
        <a href="{{ route('due-repayments.index', array_filter(['branch_id' => $selectedCalendarBranchId])) }}" class="mt-4 inline-flex text-sm font-semibold text-brand-700">View due today</a>
    </section>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
    <section class="df-panel p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Cash Movement</h2>
                <p class="text-sm text-slate-500">Daily inflow, outflow, and available teller position.</p>
            </div>
            <span class="df-badge border-brand-500/20 bg-brand-50 text-brand-800">Today</span>
        </div>
        <div class="mt-6 grid h-64 grid-cols-3 items-end gap-4 border-b border-slate-200 px-2 pb-3">
            @foreach ($cashRows as [$label, $amount, $barClass])
                @php($height = max(8, ($amount / $cashMax) * 100))
                <div class="flex h-full flex-col justify-end gap-3">
                    <div class="rounded-t-2xl {{ $barClass }} shadow-lg shadow-slate-200" style="height: {{ $height }}%"></div>
                    <div>
                        <div class="text-xs font-semibold text-slate-700">{{ $label }}</div>
                        <div class="text-xs text-slate-500">{{ number_format($amount, 0) }} UGX</div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="df-panel p-5">
        <h2 class="text-lg font-semibold text-slate-950">Portfolio Mix</h2>
        <p class="text-sm text-slate-500">Savings, lending exposure, and cash on hand.</p>
        <div class="mt-6 space-y-5">
            @foreach ($portfolioRows as [$label, $amount, $barClass])
                @php($width = ($amount / $portfolioTotal) * 100)
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-slate-700">{{ $label }}</span>
                        <span class="text-slate-500">{{ number_format($amount, 0) }} UGX</span>
                    </div>
                    <div class="h-3 rounded-full bg-slate-100">
                        <div class="h-3 rounded-full {{ $barClass }}" style="width: {{ $width }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>

<section class="df-panel mt-6 p-5">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">System Alerts</h2>
            <p class="text-sm text-slate-500">Items that need operational attention.</p>
        </div>
    </div>
    <div class="mt-4 grid gap-3 md:grid-cols-3">
        @foreach ($alerts as $label => $value)
            <div class="df-soft-panel p-4 text-sm">
                <div class="flex items-center gap-3">
                    <span class="grid h-9 w-9 place-items-center rounded-lg bg-white text-brand-800 shadow-sm">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 4.3 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0z"/></svg>
                    </span>
                    <div>
                        <div class="font-medium text-slate-600">{{ $label }}</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
@endsection
