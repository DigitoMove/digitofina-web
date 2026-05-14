@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap justify-between gap-3">
    <div><h1 class="text-2xl font-semibold">{{ $loan->loan_number }}</h1><p class="text-sm text-slate-500">{{ $loan->client->full_name }} · {{ $loan->product->name }}</p></div>
    <span class="h-fit rounded bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">{{ str($loan->status)->headline() }}</span>
</div>

<div class="grid gap-5 xl:grid-cols-3">
    <section class="rounded border bg-white p-5">
        <h2 class="font-semibold">Application</h2>
        <dl class="mt-4 grid gap-3 text-sm">
            <div><dt class="text-slate-500">Requested</dt><dd>UGX {{ number_format($loan->requested_amount, 2) }}</dd></div>
            <div><dt class="text-slate-500">Approved</dt><dd>UGX {{ number_format($loan->approved_amount ?? 0, 2) }}</dd></div>
            <div><dt class="text-slate-500">Balances</dt><dd>Principal {{ number_format($loan->principal_balance, 2) }} · Interest {{ number_format($loan->interest_balance, 2) }} · Penalty {{ number_format($loan->penalty_balance, 2) }}</dd></div>
            <div><dt class="text-slate-500">Purpose</dt><dd>{{ $loan->purpose }}</dd></div>
            <div><dt class="text-slate-500">Repayment</dt><dd>{{ $loan->term_periods }} {{ str($loan->repayment_frequency)->headline() }} installment(s)</dd></div>
            <div><dt class="text-slate-500">Savings at application</dt><dd>UGX {{ number_format($loan->savings_balance_at_application, 2) }} / Required {{ number_format($loan->required_savings_amount, 2) }}</dd></div>
        </dl>
    </section>

    <section class="rounded border bg-white p-5 xl:col-span-2">
        <h2 class="font-semibold">Workflow</h2>
        @if(in_array($loan->status, ['submitted', 'under_review', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification']))
            <form method="POST" action="{{ route('loans.approve', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_auto]">@csrf<input name="approved_amount" type="number" step="0.01" value="{{ old('approved_amount', $loan->requested_amount) }}" class="rounded border-slate-300"><button class="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Approve</button><textarea name="comments" class="rounded border-slate-300 md:col-span-2" placeholder="Approval comments">{{ old('comments') }}</textarea></form>
            <form method="POST" action="{{ route('loans.reject', $loan) }}" class="mt-3 grid gap-3 md:grid-cols-[1fr_auto]">@csrf<input name="reason" class="rounded border-slate-300" placeholder="Rejection reason"><button class="rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white">Reject</button></form>
        @endif
        @if($loan->status === 'approved')
            <form method="POST" action="{{ route('loans.disburse', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-[240px_auto]">@csrf<select name="payment_method" class="rounded border-slate-300"><option value="transfer_to_savings">Transfer to savings</option><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select><button class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Disburse</button></form>
        @endif
        @if(in_array($loan->status, ['active', 'in_arrears']))
            <form method="POST" action="{{ route('loans.repayments.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-5">@csrf<input name="amount" type="number" step="0.01" class="rounded border-slate-300" placeholder="Amount"><select name="payment_method" class="rounded border-slate-300"><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank">Bank</option><option value="savings_deduction">Savings deduction</option><option value="cheque">Cheque</option></select><input name="reference" class="rounded border-slate-300" placeholder="Reference"><input name="paid_at" type="date" value="{{ now()->toDateString() }}" class="rounded border-slate-300"><button class="rounded bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Post repayment</button></form>
        @endif
    </section>
</div>

<div class="mt-5 grid gap-5 xl:grid-cols-2">
    <section class="rounded border bg-white p-5">
        <h2 class="font-semibold">Guarantors</h2>
        <form method="POST" action="{{ route('loans.guarantors.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">@csrf<select name="type" class="rounded border-slate-300"><option value="existing_client">Existing client</option><option value="external">External</option></select><select name="guarantor_client_id" class="rounded border-slate-300"><option value="">Select member</option>@foreach($guarantorClients as $client)<option value="{{ $client->id }}">{{ $client->client_number }} - {{ $client->full_name }}</option>@endforeach</select><input name="name" class="rounded border-slate-300" placeholder="Name"><input name="phone" class="rounded border-slate-300" placeholder="Phone"><input name="national_id" class="rounded border-slate-300" placeholder="National ID"><input name="relationship" class="rounded border-slate-300" placeholder="Relationship"><input name="guaranteed_amount" type="number" step="0.01" class="rounded border-slate-300" placeholder="Guaranteed amount"><select name="status" class="rounded border-slate-300"><option value="pending">Pending</option><option value="accepted">Accepted</option><option value="rejected">Rejected</option></select><textarea name="address" class="rounded border-slate-300 md:col-span-2" placeholder="Address"></textarea><button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white md:col-span-2">Add guarantor</button></form>
        <div class="mt-4 divide-y text-sm">@forelse($loan->loanGuarantors as $guarantor)<div class="flex items-center justify-between gap-3 py-3"><div><div class="font-semibold">{{ $guarantor->name }}</div><div class="text-slate-500">UGX {{ number_format($guarantor->guaranteed_amount, 2) }} · {{ str($guarantor->status)->headline() }}</div></div><form method="POST" action="{{ route('loans.guarantors.status', [$loan, $guarantor]) }}" class="flex gap-2">@csrf @method('PUT')<select name="status" class="rounded border-slate-300 text-sm"><option value="accepted">Accepted</option><option value="rejected">Rejected</option><option value="released">Released</option></select><button class="rounded border px-3 py-1 text-xs font-semibold">Update</button></form></div>@empty<div class="py-3 text-sm text-slate-500">No guarantors recorded.</div>@endforelse</div>
    </section>

    <section class="rounded border bg-white p-5">
        <h2 class="font-semibold">Collateral</h2>
        <form method="POST" action="{{ route('loans.collaterals.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">@csrf<select name="type" class="rounded border-slate-300">@foreach(['land','vehicle','electronics','household_item','business_asset','livestock','salary','other'] as $type)<option value="{{ $type }}">{{ str($type)->headline() }}</option>@endforeach</select><input name="estimated_value" type="number" step="0.01" class="rounded border-slate-300" placeholder="Estimated value"><input name="forced_sale_value" type="number" step="0.01" class="rounded border-slate-300" placeholder="Forced sale value"><input name="ownership_proof" class="rounded border-slate-300" placeholder="Ownership proof"><input name="valuation_date" type="date" class="rounded border-slate-300"><select name="status" class="rounded border-slate-300"><option value="pending_verification">Pending verification</option><option value="verified">Verified</option><option value="rejected">Rejected</option></select><textarea name="description" class="rounded border-slate-300 md:col-span-2" placeholder="Description"></textarea><button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white md:col-span-2">Add collateral</button></form>
        <div class="mt-4 divide-y text-sm">@foreach($loan->collaterals as $collateral)<div class="flex items-center justify-between gap-3 py-3"><div><div class="font-semibold">{{ str($collateral->type)->headline() }}</div><div class="text-slate-500">UGX {{ number_format($collateral->estimated_value, 2) }} · {{ str($collateral->status)->headline() }}</div></div><form method="POST" action="{{ route('loans.collaterals.status', [$loan, $collateral]) }}" class="flex gap-2">@csrf @method('PUT')<select name="status" class="rounded border-slate-300 text-sm"><option value="verified">Verified</option><option value="rejected">Rejected</option><option value="released">Released</option><option value="seized">Seized</option></select><button class="rounded border px-3 py-1 text-xs font-semibold">Update</button></form></div>@endforeach</div>
    </section>
</div>

<section class="mt-5 rounded border bg-white p-5">
    <h2 class="font-semibold">Repayment Schedule</h2>
    <div class="mt-3 max-h-96 overflow-auto rounded border"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-2">#</th><th>Due date</th><th>Principal</th><th>Interest</th><th>Penalty</th><th>Paid</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>@foreach($loan->schedules as $schedule)<tr class="border-t"><td class="p-2">{{ $schedule->installment_number }}</td><td>{{ $schedule->due_date->toDateString() }}</td><td>{{ number_format($schedule->principal_due, 2) }}</td><td>{{ number_format($schedule->interest_due, 2) }}</td><td>{{ number_format($schedule->penalty_due, 2) }}</td><td>{{ number_format($schedule->total_paid, 2) }}</td><td>{{ number_format($schedule->outstanding_amount, 2) }}</td><td>{{ str($schedule->status)->headline() }}</td></tr>@endforeach</tbody></table></div>
</section>

<section class="mt-5 rounded border bg-white p-5">
    <h2 class="font-semibold">Activity</h2>
    <div class="mt-3 grid gap-4 lg:grid-cols-3">
        <div><h3 class="text-sm font-semibold">Approvals</h3><div class="mt-2 divide-y text-sm">@foreach($loan->approvals as $approval)<div class="py-2">{{ str($approval->status)->headline() }} by {{ optional($approval->approver)->name ?? 'System' }}<div class="text-slate-500">{{ $approval->acted_at?->format('d M Y H:i') }}</div></div>@endforeach</div></div>
        <div><h3 class="text-sm font-semibold">Disbursements</h3><div class="mt-2 divide-y text-sm">@foreach($loan->disbursements as $disbursement)<div class="py-2">UGX {{ number_format($disbursement->net_amount, 2) }} via {{ str($disbursement->payment_method)->headline() }}<div class="text-slate-500">{{ $disbursement->transaction_reference }}</div></div>@endforeach</div></div>
        <div><h3 class="text-sm font-semibold">Repayments</h3><div class="mt-2 divide-y text-sm">@foreach($loan->repayments as $repayment)<div class="py-2"><a class="font-semibold text-blue-700" href="{{ route('loan-repayments.receipt', $repayment) }}">UGX {{ number_format($repayment->amount, 2) }}</a><div class="text-slate-500">{{ $repayment->paid_at->format('d M Y') }} · {{ optional($repayment->receivedBy)->name }}</div></div>@endforeach</div></div>
    </div>
</section>
@endsection
