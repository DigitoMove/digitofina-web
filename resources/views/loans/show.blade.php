@extends('layouts.app')

@section('content')
@php
    $workflow = ['draft', 'submitted', 'review', 'approved', 'active', 'closed'];
    $statusIndex = match (true) {
        in_array($loan->status, ['draft', 'pending_guarantor_approval', 'pending_collateral_verification'], true) => 0,
        in_array($loan->status, ['submitted', 'under_review', 'recommended'], true) => 1,
        $loan->status === 'approved' => 3,
        in_array($loan->status, ['disbursed', 'active', 'in_arrears', 'restructured'], true) => 4,
        in_array($loan->status, ['fully_paid', 'closed'], true) => 5,
        default => 2,
    };
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">{{ $loan->loan_number }}</h1>
        <p class="df-muted">{{ $loan->client->full_name }} · {{ $loan->product->name }}</p>
    </div>
    <span class="df-badge {{ in_array($loan->status, ['active', 'fully_paid', 'closed'], true) ? 'border-forest-500/20 bg-forest-50 text-forest-700' : 'border-sun-400/20 bg-sun-50 text-sun-600' }}">{{ str($loan->status)->headline() }}</span>
</div>

<section class="df-panel mb-5 p-5">
    <div class="grid gap-3 md:grid-cols-6">
        @foreach ($workflow as $index => $label)
            <div class="df-step {{ $index < $statusIndex ? 'is-complete' : '' }} {{ $index === $statusIndex ? 'is-active' : '' }}">
                <span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">{{ $index + 1 }}</span>
                {{ str($label)->headline() }}
            </div>
        @endforeach
    </div>
</section>

<div data-df-tabs class="grid gap-5">
    <div class="df-tablist" role="tablist">
        <button type="button" class="df-tab" data-df-tab-target="summary">Summary</button>
        <button type="button" class="df-tab" data-df-tab-target="workflow">Workflow</button>
        <button type="button" class="df-tab" data-df-tab-target="security">Guarantors & Collateral</button>
        <button type="button" class="df-tab" data-df-tab-target="schedule">Schedule</button>
        <button type="button" class="df-tab" data-df-tab-target="activity">Activity</button>
    </div>

    <section data-df-tab-panel="summary" class="grid gap-5 xl:grid-cols-3">
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Application</h2>
            <dl class="mt-4 grid gap-3 text-sm">
                <div><dt class="text-slate-500">Requested</dt><dd class="font-semibold">UGX {{ number_format($loan->requested_amount, 2) }}</dd></div>
                <div><dt class="text-slate-500">Approved</dt><dd>UGX {{ number_format($loan->approved_amount ?? 0, 2) }}</dd></div>
                <div><dt class="text-slate-500">Purpose</dt><dd>{{ $loan->purpose }}</dd></div>
                <div><dt class="text-slate-500">Repayment</dt><dd>{{ $loan->term_periods }} {{ str($loan->repayment_frequency)->headline() }} installment(s)</dd></div>
                <div><dt class="text-slate-500">Savings at application</dt><dd>UGX {{ number_format($loan->savings_balance_at_application, 2) }} / Required {{ number_format($loan->required_savings_amount, 2) }}</dd></div>
            </dl>
        </div>

        <div class="df-panel p-5 xl:col-span-2">
            <h2 class="font-semibold text-slate-950">Balances</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-4">
                @foreach ([['Principal', $loan->principal_balance], ['Interest', $loan->interest_balance], ['Fees', $loan->fees_balance], ['Penalty', $loan->penalty_balance]] as [$label, $amount])
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                        <div class="mt-2 text-xl font-semibold text-slate-950">{{ number_format($amount, 2) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section data-df-tab-panel="workflow" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Workflow Actions</h2>
        @if(in_array($loan->status, ['submitted', 'under_review', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification']))
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <form method="POST" action="{{ route('loans.approve', $loan) }}" class="grid gap-3 rounded-lg border border-forest-500/20 bg-forest-50 p-4">
                    @csrf
                    <input name="approved_amount" type="number" step="0.01" value="{{ old('approved_amount', $loan->requested_amount) }}">
                    <textarea name="comments" placeholder="Approval comments">{{ old('comments') }}</textarea>
                    <button class="df-action-primary">Approve Loan</button>
                </form>
                <form method="POST" action="{{ route('loans.reject', $loan) }}" class="grid gap-3 rounded-lg border border-coral-500/20 bg-coral-50 p-4">
                    @csrf
                    <input name="reason" placeholder="Rejection reason">
                    <button class="df-action-danger">Reject Loan</button>
                </form>
            </div>
        @endif

        @if($loan->status === 'approved')
            <form method="POST" action="{{ route('loans.disburse', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-brand-200 bg-brand-50 p-4 md:grid-cols-[240px_auto]">
                @csrf
                <select name="payment_method"><option value="transfer_to_savings">Transfer to savings</option><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select>
                <button class="df-action-primary">Disburse</button>
            </form>
        @endif

        @if(in_array($loan->status, ['active', 'in_arrears']))
            <form method="POST" action="{{ route('loans.repayments.store', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-5">
                @csrf
                <input name="amount" type="number" step="0.01" placeholder="Amount">
                <select name="payment_method"><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank">Bank</option><option value="savings_deduction">Savings deduction</option><option value="cheque">Cheque</option></select>
                <input name="reference" placeholder="Reference">
                <input name="paid_at" type="date" value="{{ now()->toDateString() }}">
                <button class="df-action-primary">Post repayment</button>
            </form>
        @endif
    </section>

    <section data-df-tab-panel="security" class="grid gap-5 xl:grid-cols-2">
        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Guarantors</h2>
            <form method="POST" action="{{ route('loans.guarantors.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">
                @csrf
                <select name="type"><option value="existing_client">Existing client</option><option value="external">External</option></select>
                <select name="guarantor_client_id"><option value="">Select member</option>@foreach($guarantorClients as $client)<option value="{{ $client->id }}">{{ $client->client_number }} - {{ $client->full_name }}</option>@endforeach</select>
                <input name="name" placeholder="Name"><input name="phone" placeholder="Phone"><input name="national_id" placeholder="National ID"><input name="relationship" placeholder="Relationship"><input name="guaranteed_amount" type="number" step="0.01" placeholder="Guaranteed amount"><select name="status"><option value="pending">Pending</option><option value="accepted">Accepted</option><option value="rejected">Rejected</option></select>
                <textarea name="address" class="md:col-span-2" placeholder="Address"></textarea>
                <button class="df-action-secondary md:col-span-2">Add guarantor</button>
            </form>
            <div class="mt-4 divide-y divide-slate-200 text-sm">@forelse($loan->loanGuarantors as $guarantor)<div class="flex items-center justify-between gap-3 py-3"><div><div class="font-semibold">{{ $guarantor->name }}</div><div class="text-slate-500">UGX {{ number_format($guarantor->guaranteed_amount, 2) }} · {{ str($guarantor->status)->headline() }}</div></div><form method="POST" action="{{ route('loans.guarantors.status', [$loan, $guarantor]) }}" class="flex gap-2">@csrf @method('PUT')<select name="status" class="text-sm"><option value="accepted">Accepted</option><option value="rejected">Rejected</option><option value="released">Released</option></select><button class="df-action-secondary px-3 py-1 text-xs">Update</button></form></div>@empty<div class="py-3 text-sm text-slate-500">No guarantors recorded.</div>@endforelse</div>
        </div>

        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Collateral</h2>
            <form method="POST" action="{{ route('loans.collaterals.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">
                @csrf
                <select name="type">@foreach(['land','vehicle','electronics','household_item','business_asset','livestock','salary','other'] as $type)<option value="{{ $type }}">{{ str($type)->headline() }}</option>@endforeach</select>
                <input name="estimated_value" type="number" step="0.01" placeholder="Estimated value"><input name="forced_sale_value" type="number" step="0.01" placeholder="Forced sale value"><input name="ownership_proof" placeholder="Ownership proof"><input name="valuation_date" type="date"><select name="status"><option value="pending_verification">Pending verification</option><option value="verified">Verified</option><option value="rejected">Rejected</option></select>
                <textarea name="description" class="md:col-span-2" placeholder="Description"></textarea>
                <button class="df-action-secondary md:col-span-2">Add collateral</button>
            </form>
            <div class="mt-4 divide-y divide-slate-200 text-sm">@foreach($loan->collaterals as $collateral)<div class="flex items-center justify-between gap-3 py-3"><div><div class="font-semibold">{{ str($collateral->type)->headline() }}</div><div class="text-slate-500">UGX {{ number_format($collateral->estimated_value, 2) }} · {{ str($collateral->status)->headline() }}</div></div><form method="POST" action="{{ route('loans.collaterals.status', [$loan, $collateral]) }}" class="flex gap-2">@csrf @method('PUT')<select name="status" class="text-sm"><option value="verified">Verified</option><option value="rejected">Rejected</option><option value="released">Released</option><option value="seized">Seized</option></select><button class="df-action-secondary px-3 py-1 text-xs">Update</button></form></div>@endforeach</div>
        </div>
    </section>

    <section data-df-tab-panel="schedule" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Repayment Schedule</h2>
        <div class="mt-3 max-h-96 overflow-auto rounded-lg border border-slate-200"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-2">#</th><th>Due date</th><th>Principal</th><th>Interest</th><th>Penalty</th><th>Paid</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>@foreach($loan->schedules as $schedule)<tr class="border-t border-slate-200"><td class="p-2">{{ $schedule->installment_number }}</td><td>{{ $schedule->due_date->toDateString() }}</td><td>{{ number_format($schedule->principal_due, 2) }}</td><td>{{ number_format($schedule->interest_due, 2) }}</td><td>{{ number_format($schedule->penalty_due, 2) }}</td><td>{{ number_format($schedule->total_paid, 2) }}</td><td>{{ number_format($schedule->outstanding_amount, 2) }}</td><td>{{ str($schedule->status)->headline() }}</td></tr>@endforeach</tbody></table></div>
    </section>

    <section data-df-tab-panel="activity" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Activity</h2>
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <div><h3 class="text-sm font-semibold">Approvals</h3><div class="mt-2 divide-y divide-slate-200 text-sm">@foreach($loan->approvals as $approval)<div class="py-2">{{ str($approval->status)->headline() }} by {{ optional($approval->approver)->name ?? 'System' }}<div class="text-slate-500">{{ $approval->acted_at?->format('d M Y H:i') }}</div></div>@endforeach</div></div>
            <div><h3 class="text-sm font-semibold">Disbursements</h3><div class="mt-2 divide-y divide-slate-200 text-sm">@foreach($loan->disbursements as $disbursement)<div class="py-2">UGX {{ number_format($disbursement->net_amount, 2) }} via {{ str($disbursement->payment_method)->headline() }}<div class="text-slate-500">{{ $disbursement->transaction_reference }}</div></div>@endforeach</div></div>
            <div><h3 class="text-sm font-semibold">Repayments</h3><div class="mt-2 divide-y divide-slate-200 text-sm">@foreach($loan->repayments as $repayment)<div class="py-2"><a class="font-semibold text-blue-700" href="{{ route('loan-repayments.receipt', $repayment) }}">UGX {{ number_format($repayment->amount, 2) }}</a><div class="text-slate-500">{{ $repayment->paid_at->format('d M Y') }} · {{ optional($repayment->receivedBy)->name }}</div></div>@endforeach</div></div>
        </div>
    </section>
</div>
@endsection
