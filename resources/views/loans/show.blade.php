@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap justify-between gap-3"><div><h1 class="text-2xl font-semibold">{{ $loan->loan_number }}</h1><p class="text-sm text-slate-500">{{ $loan->client->full_name }} · {{ $loan->product->name }}</p></div><span class="rounded bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">{{ str($loan->status)->headline() }}</span></div>
<div class="grid gap-5 xl:grid-cols-3">
    <section class="rounded border bg-white p-5"><h2 class="font-semibold">Application</h2><dl class="mt-4 grid gap-3 text-sm"><div><dt class="text-slate-500">Requested</dt><dd>{{ number_format($loan->requested_amount, 2) }} UGX</dd></div><div><dt class="text-slate-500">Approved</dt><dd>{{ number_format($loan->approved_amount ?? 0, 2) }} UGX</dd></div><div><dt class="text-slate-500">Principal balance</dt><dd>{{ number_format($loan->principal_balance, 2) }} UGX</dd></div><div><dt class="text-slate-500">Frequency</dt><dd>{{ ucfirst($loan->repayment_frequency) }}</dd></div></dl></section>
    <section class="rounded border bg-white p-5 xl:col-span-2">
        <h2 class="font-semibold">Workflow</h2>
        @if(in_array($loan->status, ['submitted', 'under_review', 'recommended']))
            <form method="POST" action="{{ route('loans.approve', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_auto]">@csrf<input name="approved_amount" type="number" value="{{ $loan->requested_amount }}" class="rounded border-slate-300"><button class="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Approve</button><textarea name="comments" class="rounded border-slate-300 md:col-span-2" placeholder="Approval comments"></textarea></form>
        @endif
        @if($loan->status === 'approved')
            <form method="POST" action="{{ route('loans.disburse', $loan) }}" class="mt-4">@csrf<button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Disburse to Savings Account</button></form>
        @endif
        <h3 class="mt-6 font-semibold">Repayment schedule</h3>
        <div class="mt-3 max-h-96 overflow-auto rounded border"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-2">#</th><th>Due date</th><th>Principal</th><th>Interest</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>@foreach($loan->schedules as $schedule)<tr class="border-t"><td class="p-2">{{ $schedule->installment_number }}</td><td>{{ $schedule->due_date->toDateString() }}</td><td>{{ number_format($schedule->principal_due, 2) }}</td><td>{{ number_format($schedule->interest_due, 2) }}</td><td>{{ number_format($schedule->outstanding_amount, 2) }}</td><td>{{ str($schedule->status)->headline() }}</td></tr>@endforeach</tbody></table></div>
    </section>
</div>
@endsection
