@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="df-muted">Only active and in-arrears loans are included.</p>
        <h1 class="df-title">Loan Arrears</h1>
    </div>
    @if($canRunManualPenalties)
        <form method="POST" action="{{ route('arrears.apply-penalties', ['branch_id' => $selectedBranchId ?: null]) }}">@csrf<button class="df-action-danger">Run Penalties</button></form>
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
<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50"><tr><th class="p-3">Client</th><th>Loan</th><th>Branch</th><th>Officer</th><th>Due schedules</th><th>Earliest due</th><th>Max days due</th><th>Amount to clear</th><th>Penalty</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($loans as $loan)
                @php
                    $arrearsSchedules = $loan->schedules;
                    $earliestSchedule = $arrearsSchedules->first();
                    $maxDaysDue = $arrearsSchedules->max(fn ($schedule) => (int) ($schedule->days_overdue ?: ($schedule->due_date ? $calendar->businessDaysBetween($schedule->due_date, now()) : 0)));
                    $scheduleSummary = $arrearsSchedules
                        ->map(fn ($schedule) => '#'.$schedule->installment_number.' ('.$schedule->due_date?->format('d M Y').')')
                        ->implode(', ');
                @endphp
                <tr class="border-t">
                    <td class="p-3">{{ $loan->client?->full_name ?? 'N/A' }}</td>
                    <td><a class="font-semibold text-blue-700" href="{{ route('loans.show', $loan) }}">{{ $loan->loan_number }}</a><div class="text-slate-500">{{ $loan->product?->name ?? 'N/A' }}</div></td>
                    <td>{{ $loan->branch?->name ?? 'Unassigned' }}</td>
                    <td>{{ $loan->officer?->name ?? 'Unassigned' }}</td>
                    <td>
                        <div class="font-semibold text-slate-900">{{ number_format((int) $loan->arrears_schedules_count) }} due</div>
                        <div class="max-w-xs text-xs text-slate-500">{{ $scheduleSummary }}</div>
                    </td>
                    <td>{{ $earliestSchedule?->due_date?->format('d M Y') ?? 'N/A' }}</td>
                    <td>{{ number_format((int) $maxDaysDue) }}</td>
                    <td class="font-semibold text-red-700">UGX {{ number_format((float) $loan->arrears_cumulative_due, 2) }}</td>
                    <td>UGX {{ number_format((float) $loan->arrears_penalty_due, 2) }}</td>
                    <td><span class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">{{ str($loan->status)->headline() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="10" class="p-6 text-center text-slate-500">No overdue loans found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $loans->links() }}</div>
@endsection
