@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="df-muted">Only active and in-arrears loans are included.</p>
        <h1 class="df-title">Loan Arrears</h1>
    </div>
    @if($canRunManualPenalties)
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('arrears.apply-penalties', ['branch_id' => $selectedBranchId ?: null]) }}">@csrf<button class="df-action-danger">Run Penalties</button></form>
            <form method="POST" action="{{ route('arrears.clear-penalties') }}" onsubmit="return confirm('Clear all pending penalties for the selected branch scope?')">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $selectedBranchId ?: '' }}">
                <button class="df-action-secondary">Clear All Penalties</button>
            </form>
        </div>
    @endif
</div>
<form class="df-panel mb-4 p-4">
    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-[minmax(18rem,1fr)_repeat(4,minmax(9.5rem,12rem))_auto_auto]">
        <label class="text-sm font-semibold text-slate-700 xl:min-w-0">Search
            <input name="search" value="{{ request('search') }}" placeholder="Client, phone, or loan number" class="mt-1">
        </label>
        <label class="text-sm font-semibold text-slate-700">Aging bucket
            <select name="bucket" class="mt-1"><option value="">All buckets</option>@foreach(['1-30','31-60','61-90','91-180','180+'] as $bucket)<option value="{{ $bucket }}" @selected(request('bucket') === $bucket)>{{ $bucket }} days</option>@endforeach</select>
        </label>
        <label class="text-sm font-semibold text-slate-700">Branch
            <select name="branch_id" class="mt-1" @disabled(! $canFilterBranch)>
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) request('branch_id', $selectedBranchId) === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-700">Product
            <select name="loan_product_id" class="mt-1">
                <option value="">All products</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}" @selected((int) request('loan_product_id') === $product->id)>{{ $product->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-700">Officer
            <select name="officer_id" class="mt-1" @disabled(! $canFilterOfficer)>
                <option value="">All officers</option>
                @foreach($officers as $officer)
                    <option value="{{ $officer->id }}" @selected((int) request('officer_id') === $officer->id)>{{ $officer->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="df-action-primary sm:col-span-2 lg:col-span-1 xl:col-span-1 xl:w-auto">Filter</button>
        <a href="{{ route('arrears.index') }}" class="df-action-secondary text-center sm:col-span-2 lg:col-span-1 xl:col-span-1 xl:w-auto">Reset</a>
    </div>
</form>
<div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
    <table class="min-w-[1180px] w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
            <tr>
                <th class="px-4 py-3">Client</th>
                <th class="px-4 py-3">Loan</th>
                <th class="px-4 py-3">Branch</th>
                <th class="px-4 py-3">Officer</th>
                <th class="px-4 py-3">Schedules</th>
                <th class="px-4 py-3">Earliest due</th>
                <th class="px-4 py-3 text-right">Days</th>
                <th class="px-4 py-3 text-right">Missed</th>
                <th class="px-4 py-3 text-right">Penalty</th>
                <th class="px-4 py-3 text-right">Total</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse($loans as $loan)
                @php
                    $arrearsSchedules = $loan->schedules;
                    $earliestSchedule = $arrearsSchedules->first();
                    $maxDaysDue = $arrearsSchedules->max(fn ($schedule) => (int) ($schedule->days_overdue ?: ($schedule->due_date ? $calendar->businessDaysBetween($schedule->due_date, now()) : 0)));
                    $penaltyToClear = max(0, (float) $loan->arrears_penalty_due - (float) $loan->arrears_penalty_paid);
                    $missedAmount = max(0, (float) $loan->arrears_cumulative_due - $penaltyToClear);
                    $totalToPay = $missedAmount + $penaltyToClear;
                @endphp
                <tr class="align-top hover:bg-slate-50/70">
                    <td class="px-4 py-3 font-medium text-slate-900">{{ $loan->client?->full_name ?? 'N/A' }}</td>
                    <td class="px-4 py-3">
                        <a class="font-semibold text-teal-700 hover:text-teal-900" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a>
                        <div class="mt-0.5 text-xs text-slate-500">{{ $loan->product?->name ?? 'N/A' }}</div>
                    </td>
                    <td class="px-4 py-3 text-slate-700">{{ $loan->branch?->name ?? 'Unassigned' }}</td>
                    <td class="px-4 py-3 text-slate-700">{{ $loan->officer?->name ?? 'Unassigned' }}</td>
                    <td class="px-4 py-3">
                        <details class="group/schedules">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                <span>{{ number_format((int) $loan->arrears_schedules_count) }} due</span>
                                <span class="text-slate-400 group-open/schedules:rotate-180">v</span>
                            </summary>
                            <div class="mt-2 w-[28rem] max-w-[70vw] rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
                                <table class="w-full text-xs">
                                    <thead class="text-slate-400">
                                        <tr>
                                            <th class="px-2 py-1 text-left">Installment</th>
                                            <th class="px-2 py-1 text-right">Missed</th>
                                            <th class="px-2 py-1 text-right">Penalty</th>
                                            <th class="px-2 py-1 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach($arrearsSchedules as $schedule)
                                            @php
                                                $schedulePenalty = max(0, (float) $schedule->penalty_due - (float) $schedule->penalty_paid);
                                                $scheduleMissed = max(0, (float) $schedule->outstanding_amount - $schedulePenalty);
                                            @endphp
                                            <tr>
                                                <td class="px-2 py-1.5 font-medium text-slate-700">#{{ $schedule->installment_number }} · {{ $schedule->due_date?->format('d M Y') }}</td>
                                                <td class="px-2 py-1.5 text-right text-slate-700">UGX {{ number_format($scheduleMissed, 2) }}</td>
                                                <td class="px-2 py-1.5 text-right text-slate-700">UGX {{ number_format($schedulePenalty, 2) }}</td>
                                                <td class="px-2 py-1.5 text-right">
                                                    @if($canRunManualPenalties && $schedulePenalty > 0)
                                                        <form method="POST" action="{{ route('arrears.clear-penalties') }}" onsubmit="return confirm('Clear this schedule penalty?')">
                                                            @csrf
                                                            <input type="hidden" name="loan_schedule_id" value="{{ $schedule->id }}">
                                                            <button class="text-xs font-semibold text-red-700 hover:text-red-900">Clear</button>
                                                        </form>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-700">{{ $earliestSchedule?->due_date?->format('d M Y') ?? 'N/A' }}</td>
                    <td class="px-4 py-3 text-right text-slate-700">{{ number_format((int) $maxDaysDue) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-right font-semibold text-red-700">UGX {{ number_format($missedAmount, 2) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-right text-slate-700">UGX {{ number_format($penaltyToClear, 2) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-right font-semibold text-slate-950">UGX {{ number_format($totalToPay, 2) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">{{ str($loan->status)->headline() }}</span></td>
                    <td class="px-4 py-3 text-right">
                        @if($canRunManualPenalties && $penaltyToClear > 0)
                            <form method="POST" action="{{ route('arrears.clear-penalties') }}" onsubmit="return confirm('Clear all pending penalties for this loan?')">
                                @csrf
                                <input type="hidden" name="loan_id" value="{{ $loan->id }}">
                                <button class="whitespace-nowrap rounded-md border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Clear penalty</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="12" class="p-6 text-center text-slate-500">No overdue loans found.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-4">{{ $loans->links() }}</div>
@endsection
