@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Due Repayments</h1>
        <p class="text-sm text-slate-500">Installments scheduled for {{ $date->format('d M Y') }}.</p>
    </div>
    <a href="{{ route('dashboard') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back to Dashboard</a>
</div>

<form class="mb-4 flex flex-wrap items-end gap-3 rounded border bg-white p-4">
    <label class="text-sm font-medium text-slate-700">Repayment date
        <input name="date" value="{{ $date->toDateString() }}" type="date" class="mt-1 rounded border-slate-300">
    </label>
    <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">View Date</button>
    <div class="ml-auto text-sm text-slate-500">Visible total: <span class="font-semibold text-slate-950">UGX {{ number_format($totalAmount, 2) }}</span></div>
</form>

<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="p-3">Client</th>
                <th>Contact</th>
                <th>Loan</th>
                <th>Product</th>
                <th>Installment</th>
                <th>Amount to pay</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($schedules as $schedule)
                <tr class="border-t">
                    <td class="p-3 font-medium text-slate-950">{{ $schedule->loan->client->full_name }}</td>
                    <td>
                        <div>{{ $schedule->loan->client->phone }}</div>
                        @if($schedule->loan->client->alternative_phone)
                            <div class="text-xs text-slate-500">{{ $schedule->loan->client->alternative_phone }}</div>
                        @endif
                    </td>
                    <td><a class="font-semibold text-blue-700" href="{{ route('loans.show', $schedule->loan) }}">{{ $schedule->loan->loan_number }}</a></td>
                    <td>{{ $schedule->loan->product->name }}</td>
                    <td>#{{ $schedule->installment_number }}</td>
                    <td>UGX {{ number_format($schedule->outstanding_amount, 2) }}</td>
                    <td><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ str($schedule->status)->headline() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-6 text-center text-slate-500">No repayments scheduled for this date.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $schedules->links() }}</div>
@endsection
