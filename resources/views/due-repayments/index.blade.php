@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $isCashier ? "Today's Collection Calls" : 'Due Repayments' }}</h1>
        <p class="text-sm text-slate-500">{{ $isCashier ? 'Clients with installments due today at your branch.' : 'Installments scheduled for '.$date->format('d M Y').'.' }}</p>
    </div>
    <a href="{{ route('dashboard') }}" class="rounded border px-4 py-2 text-sm font-semibold">Back to Dashboard</a>
</div>

<form class="mb-4 flex flex-wrap items-end gap-3 rounded border bg-white p-4">
    @unless($isCashier)
        <label class="text-sm font-medium text-slate-700">Repayment date
            <input name="date" value="{{ $date->toDateString() }}" type="date" class="mt-1 rounded border-slate-300">
        </label>
    @endunless
    @if($canFilterBranch)
        <label class="text-sm font-medium text-slate-700">Branch
            <select name="branch_id" class="mt-1 rounded border-slate-300">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    @unless($isCashier)
        <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">View Date</button>
    @endunless
    <div class="ml-auto text-sm text-slate-500"><span class="font-semibold text-slate-950">{{ $scheduledCount }}</span> installment{{ $scheduledCount === 1 ? '' : 's' }} due</div>
</form>

<div class="overflow-hidden rounded border bg-white">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="p-3">Client</th>
                <th>Contact</th>
                <th>Loan</th>
                <th>Product</th>
                <th>Officer</th>
                <th>Installments due</th>
                <th>Amount to pay</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($schedules as $schedule)
                <tr class="border-t">
                    <td class="p-3 font-medium text-slate-950">{{ $schedule->loan->client->full_name }}</td>
                    <td>
                        <a href="tel:{{ $schedule->loan->client->phone }}" class="font-semibold text-brand-700">{{ $schedule->loan->client->phone }}</a>
                        @if($schedule->loan->client->alternative_phone)
                            <div><a href="tel:{{ $schedule->loan->client->alternative_phone }}" class="text-xs font-medium text-slate-500 hover:text-brand-700">{{ $schedule->loan->client->alternative_phone }}</a></div>
                        @endif
                    </td>
                    <td>
                        @if($isCashier)
                            <span class="font-semibold text-slate-950">{{ $schedule->loan->loan_number }}</span>
                        @else
                            <a class="font-semibold text-blue-700" href="{{ route('loans.show', $schedule->loan) }}">{{ $schedule->loan->loan_number }}</a>
                        @endif
                    </td>
                    <td>{{ $schedule->loan->product->name }}</td>
                    <td>
                        @if($schedule->loan->officer)
                            <a href="{{ route('staff-directory.show', $schedule->loan->officer) }}" class="font-semibold text-brand-700">{{ $schedule->loan->officer->name }}</a>
                            <div class="mt-1 text-xs text-slate-500">{{ $schedule->loan->officer->phone ?? $schedule->loan->officer->email }}</div>
                            @if((int) $schedule->loan->officer_id !== (int) auth()->id())
                                <a href="{{ route('chat.index', ['recipient_id' => $schedule->loan->officer_id, 'loan_id' => $schedule->loan_id]) }}" class="mt-1 inline-flex text-xs font-semibold text-brand-700">Chat reminder</a>
                            @endif
                        @else
                            <span class="text-slate-500">Unassigned</span>
                        @endif
                    </td>
                    <td>
                        <div class="font-semibold text-slate-950">{{ (int) ($schedule->loan->due_installments_count ?? 1) }} installment{{ (int) ($schedule->loan->due_installments_count ?? 1) === 1 ? '' : 's' }}</div>
                        <div class="text-xs text-slate-500">Current schedule #{{ $schedule->installment_number }}</div>
                    </td>
                    <td class="font-semibold whitespace-nowrap">UGX {{ number_format((float) ($schedule->loan->due_installments_amount ?? $schedule->outstanding_amount), 2) }}</td>
                    <td><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ str($schedule->status)->headline() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-6 text-center text-slate-500">No repayments scheduled for this date.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $schedules->links() }}</div>
@endsection
