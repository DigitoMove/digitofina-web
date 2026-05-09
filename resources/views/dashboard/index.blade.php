@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Dashboard</h1>
        <p class="text-sm text-slate-500">Branch operations, cash position, approvals, and portfolio pulse.</p>
    </div>
    <a href="{{ route('teller.index') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">Open Teller Drawer</a>
</div>

@php
    $cards = [
        ['Total clients', $totalClients],
        ['Active accounts', $activeAccounts],
        ['Total savings', number_format($totalSavings, 2).' UGX'],
        ['Total loans', number_format($totalLoans, 2).' UGX'],
        ["Today's deposits", number_format($todayDeposits, 2).' UGX'],
        ["Today's withdrawals", number_format($todayWithdrawals, 2).' UGX'],
        ['Teller drawer balance', number_format($drawerBalance, 2).' UGX'],
        ['Pending approvals', $pendingApprovals],
    ];
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as [$label, $value])
        <div class="rounded border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $value }}</div>
        </div>
    @endforeach
</div>

<section class="mt-6 rounded border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold">System alerts</h2>
    <div class="mt-4 grid gap-3 md:grid-cols-2">
        @foreach ($alerts as $label => $value)
            <div class="flex items-center justify-between rounded border border-slate-200 p-3 text-sm">
                <span class="text-slate-600">{{ $label }}</span>
                <span class="font-semibold text-slate-950">{{ $value }}</span>
            </div>
        @endforeach
    </div>
</section>
@endsection
