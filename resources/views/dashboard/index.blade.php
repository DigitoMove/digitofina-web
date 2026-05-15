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
        ['Total clients', $totalClients, 'bg-brand-50 text-brand-800', 'from-brand-500 to-brand-600', [38, 58, 44, 68, 74]],
        ['Active accounts', $activeAccounts, 'bg-emerald-50 text-emerald-700', 'from-emerald-500 to-emerald-600', [46, 52, 63, 57, 78]],
        ['Total savings', number_format($totalSavings, 2).' UGX', 'bg-brand-50 text-brand-800', 'from-brand-500 to-forest-500', [42, 55, 61, 76, 84]],
        ['Total loans', number_format($totalLoans, 2).' UGX', 'bg-brand-50 text-brand-800', 'from-brand-600 to-sun-400', [35, 48, 65, 62, 80]],
        ["Today's deposits", number_format($todayDeposits, 2).' UGX', 'bg-green-50 text-green-700', 'from-green-500 to-emerald-500', [28, 44, 50, 72, 88]],
        ["Today's withdrawals", number_format($todayWithdrawals, 2).' UGX', 'bg-amber-50 text-amber-700', 'from-amber-400 to-orange-500', [24, 40, 38, 52, 46]],
        ['Teller drawer balance', number_format($drawerBalance, 2).' UGX', 'bg-sun-50 text-sun-600', 'from-sun-400 to-brand-600', [34, 47, 57, 51, 69]],
        ['Pending approvals', $pendingApprovals, 'bg-rose-50 text-rose-700', 'from-rose-500 to-pink-500', [18, 26, 44, 32, 54]],
        ['Branches', $branchCount, 'bg-slate-100 text-slate-700', 'from-slate-500 to-brand-600', [44, 50, 54, 58, 62]],
        ['Active staff', $staffCount, 'bg-lime-50 text-lime-700', 'from-lime-500 to-emerald-500', [32, 43, 52, 68, 73]],
        ['Audit events today', $auditEventsToday, 'bg-slate-100 text-slate-700', 'from-slate-600 to-slate-800', [20, 42, 30, 56, 48]],
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
    @foreach ($cards as [$label, $value, $badgeClass, $barClass, $spark])
        <div class="df-panel df-hover-lift relative overflow-hidden p-5">
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
        </div>
    @endforeach
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
