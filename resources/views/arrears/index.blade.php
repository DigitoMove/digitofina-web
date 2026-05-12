@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-semibold">Loan Arrears</h1>
    <form method="POST" action="{{ route('arrears.apply-penalties') }}">@csrf<button class="rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white">Run Penalties</button></form>
</div>
<form class="mb-4 grid gap-3 rounded border bg-white p-4 md:grid-cols-[220px_auto]">
    <select name="bucket" class="rounded border-slate-300"><option value="">All aging buckets</option>@foreach(['1-30','31-60','61-90','91-180','180+'] as $bucket)<option value="{{ $bucket }}" @selected(request('bucket') === $bucket)>{{ $bucket }} days</option>@endforeach</select>
    <button class="w-fit rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Filter</button>
</form>
<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50"><tr><th class="p-3">Client</th><th>Loan</th><th>Due date</th><th>Days</th><th>Outstanding</th><th>Penalty</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($schedules as $schedule)
                <tr class="border-t">
                    <td class="p-3">{{ $schedule->loan->client->full_name }}</td>
                    <td><a class="font-semibold text-blue-700" href="{{ route('loans.show', $schedule->loan) }}">{{ $schedule->loan->loan_number }}</a><div class="text-slate-500">{{ $schedule->loan->product->name }}</div></td>
                    <td>{{ $schedule->due_date->format('d M Y') }}</td>
                    <td>{{ $schedule->days_overdue ?: (int) $schedule->due_date->diffInDays(now()) }}</td>
                    <td>UGX {{ number_format($schedule->outstanding_amount, 2) }}</td>
                    <td>UGX {{ number_format($schedule->penalty_due, 2) }}</td>
                    <td><span class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">{{ str($schedule->status)->headline() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-6 text-center text-slate-500">No overdue installments found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $schedules->links() }}</div>
@endsection
