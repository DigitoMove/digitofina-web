@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="text-sm font-semibold text-slate-500">Operations</div>
        <h1 class="df-title">Transaction Reversal Requests</h1>
        <p class="df-muted">Review reversal requests without digging through the teller drawer page.</p>
    </div>
    <a href="{{ route('teller.index') }}" class="df-action-secondary">Teller Drawer</a>
</div>

<section class="df-panel overflow-hidden">
    <div class="flex flex-wrap gap-2 border-b border-slate-200 px-5 py-4">
        @foreach($statuses as $statusValue => $statusLabel)
            <a href="{{ route('transaction-reversal-requests.index', array_filter(['status' => $statusValue, 'search' => $search])) }}" class="rounded-lg px-3 py-2 text-sm font-semibold transition {{ $status === $statusValue ? 'bg-brand-700 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $statusLabel }}</a>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 class="font-semibold text-slate-950">{{ $statuses[$status] }} requests</h2>
            <p class="text-sm text-slate-500">{{ $requests->total() }} request{{ $requests->total() === 1 ? '' : 's' }} found.</p>
        </div>
        <form method="GET" action="{{ route('transaction-reversal-requests.index') }}" class="flex flex-wrap items-center justify-end gap-2">
            <input type="hidden" name="status" value="{{ $status }}">
            <input name="search" value="{{ $search }}" class="min-w-72 rounded border-slate-300 text-sm" placeholder="Search reference, cashier, client, or reason">
            <button class="df-action-secondary">Search</button>
            @if($search !== '')
                <a href="{{ route('transaction-reversal-requests.index', ['status' => $status]) }}" class="rounded border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
            @endif
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="p-3">Requested</th>
                    <th>Transaction</th>
                    <th>Client / account</th>
                    <th>Cashier</th>
                    <th>Reason</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th class="text-right pr-3">Decision</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $approval)
                    @php
                        $transaction = $approval->subject;
                    @endphp
                    <tr class="border-t border-slate-200 align-top">
                        <td class="p-3 whitespace-nowrap">
                            {{ $approval->created_at->format('d M Y H:i') }}
                            <div class="text-xs text-slate-500">{{ $approval->branch?->name ?? 'No branch' }}</div>
                        </td>
                        <td>
                            @if($transaction)
                                <a class="font-semibold text-blue-700" href="{{ route('receipts.show', $transaction) }}">{{ $transaction->reference }}</a>
                                <div class="text-xs text-slate-500">{{ $transaction->type?->name ?? 'Transaction' }} · {{ $transaction->posted_at?->format('d M Y H:i:s') ?? $transaction->created_at->format('d M Y H:i:s') }}</div>
                            @else
                                <span class="text-slate-500">Transaction removed</span>
                            @endif
                        </td>
                        <td>
                            <div class="font-medium text-slate-950">{{ $transaction?->account?->client?->full_name ?? $transaction?->account?->client?->business_name ?? 'General ledger posting' }}</div>
                            <div class="text-xs text-slate-500">{{ $transaction?->account?->account_number ?? 'No client account' }}</div>
                        </td>
                        <td>{{ $approval->requestedBy?->name ?? 'N/A' }}</td>
                        <td class="max-w-md">{{ $approval->reason }}</td>
                        <td class="text-right font-semibold whitespace-nowrap">UGX {{ number_format((float) $approval->amount, 2) }}</td>
                        <td>
                            <span class="rounded px-2 py-1 text-xs font-semibold {{ $approval->status === 'pending' ? 'bg-amber-50 text-amber-700' : ($approval->status === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700') }}">{{ str($approval->status)->headline() }}</span>
                            @if($approval->checkedBy)
                                <div class="mt-1 text-xs text-slate-500">By {{ $approval->checkedBy->name }}</div>
                            @endif
                        </td>
                        <td class="pr-3 text-right">
                            @if($approval->status === 'pending')
                                <div class="grid gap-2 md:grid-cols-2">
                                    <form method="POST" action="{{ route('transactions.reversal-requests.approve', $approval) }}" class="grid gap-1" data-confirm-title="Approve reversal" data-confirm-message="Review this reversal request before approving. Approval will post the reversal." data-confirm-label="Approve reversal" data-confirm-tone="warning">
                                        @csrf
                                        <input name="comments" class="rounded border-slate-300 text-xs" placeholder="Approval note">
                                        <button class="rounded bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('transactions.reversal-requests.reject', $approval) }}" class="grid gap-1" data-confirm-title="Reject reversal" data-confirm-message="Confirm that this reversal request should be rejected." data-confirm-label="Reject request" data-confirm-tone="danger">
                                        @csrf
                                        <input name="comments" class="rounded border-slate-300 text-xs" placeholder="Reason" required>
                                        <button class="rounded bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">Reject</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-sm text-slate-500">{{ $approval->checked_at?->format('d M Y H:i') ?? 'Closed' }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-8 text-center text-slate-500">No reversal requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="mt-4">{{ $requests->links() }}</div>
@endsection
