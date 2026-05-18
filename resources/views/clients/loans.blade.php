@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Client Loan Accounts</h1>
        <p class="df-muted">{{ $client->client_number }} · {{ $client->full_name ?: $client->business_name }} · {{ $client->branch?->name }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('clients.show', $client) }}" class="df-action-secondary">Client Dashboard</a>
        <a href="{{ route('loans.create', ['client_id' => $client->id]) }}" class="df-action-primary">New Loan</a>
    </div>
</div>

<section class="df-panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-700 text-xs uppercase text-white">
                <tr>
                    <th class="px-4 py-3">Account Number</th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Officer</th>
                    <th class="px-4 py-3">Branch</th>
                    <th class="px-4 py-3">Disbursement Date</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                    <th class="px-4 py-3 text-right">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($client->loans as $loan)
                    @php
                        $statusTone = match ($loan->status) {
                            'fully_paid', 'closed' => 'bg-blue-100 text-blue-800',
                            'written_off', 'cancelled', 'rejected' => 'bg-red-100 text-red-800',
                            'in_arrears' => 'bg-rose-100 text-rose-800',
                            'active', 'disbursed' => 'bg-emerald-100 text-emerald-800',
                            'approved' => 'bg-amber-100 text-amber-800',
                            default => 'bg-slate-100 text-slate-700',
                        };
                    @endphp
                    <tr class="odd:bg-white even:bg-slate-50 hover:bg-brand-50/70">
                        <td class="px-4 py-3 font-semibold text-brand-700"><a href="{{ route('loans.show', $loan) }}">{{ $loan->loanAccount?->account_number ?? $loan->loan_number }}</a></td>
                        <td class="px-4 py-3">{{ $loan->product?->name ?? 'Loan product' }}</td>
                        <td class="px-4 py-3">{{ $loan->officer?->name ?? 'Unassigned' }}</td>
                        <td class="px-4 py-3">{{ $loan->branch?->name ?? $client->branch?->name }}</td>
                        <td class="px-4 py-3">{{ $loan->disbursed_at?->format('d-m-Y') ?? 'Not disbursed' }}</td>
                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) ($loan->approved_amount ?? $loan->requested_amount), 2) }}</td>
                        <td class="px-4 py-3 text-right"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusTone }}">{{ str($loan->status)->headline() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">This client has no loan accounts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
