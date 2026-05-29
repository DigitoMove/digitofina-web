@extends('layouts.app')
@section('content')
@php
    $pendingReversal = $transaction->reversalRequests->firstWhere('status', 'pending');
    $journalAccount = $transaction->journalEntries->first()?->chartAccount;
    $canApproveReversal = auth()->user()->canPerform('approve-reversals') || auth()->user()->canPerform('override-maker-checker');
    $canAdminReverse = auth()->user()->hasRole('super-admin') || auth()->user()->hasRole('organization-admin') || $canApproveReversal;
    $canRequestReversal = $transaction->status === 'posted'
        && ! $pendingReversal
        && ! $transaction->reversed_by
        && $canAdminReverse;
@endphp

<div class="mx-auto grid max-w-3xl gap-5">
    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
        <div class="text-center">
            <div class="text-xl font-bold">DigitoFina</div>
            <div class="text-sm text-slate-500">Transaction Receipt</div>
        </div>
        <dl class="mt-6 grid gap-3 text-sm">
            <div class="flex justify-between gap-4"><dt>Reference</dt><dd class="font-semibold">{{ $transaction->reference }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Status</dt><dd class="font-semibold capitalize {{ $transaction->status === 'reversed' ? 'text-red-600' : 'text-emerald-700' }}">{{ str_replace('_', ' ', $transaction->status) }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Type</dt><dd>{{ $transaction->type?->name ?? 'Transaction' }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Client</dt><dd>{{ $transaction->account?->client?->full_name ?? $transaction->account?->client?->business_name ?? 'General ledger posting' }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Account</dt><dd>{{ $transaction->account?->account_number ?? ($journalAccount ? $journalAccount->code.' · '.$journalAccount->name : 'Drawer ledger') }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Amount</dt><dd class="font-semibold">{{ number_format($transaction->amount, 2) }} {{ $transaction->currency }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Balance after</dt><dd>{{ number_format((float) $transaction->balance_after, 2) }} {{ $transaction->currency }}</dd></div>
            <div class="flex justify-between gap-4"><dt>Posted by</dt><dd>{{ $transaction->user->name }}</dd></div>
            @if($transaction->reversal)
                <div class="flex justify-between gap-4"><dt>Reversed by transaction</dt><dd class="font-semibold">{{ $transaction->reversal->reference }}</dd></div>
            @endif
        </dl>
        <button onclick="window.print()" class="mt-6 w-full rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white print:hidden">Print receipt</button>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm print:hidden">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Reversal Control</h2>
                <p class="text-sm text-slate-500">Reversals are restricted to admins and above.</p>
            </div>
            @if($pendingReversal)
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Pending approval</span>
            @elseif($transaction->status === 'reversed')
                <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Reversed</span>
            @endif
        </div>

        @if($canRequestReversal)
            <form method="POST" action="{{ route('transactions.reversal-requests.store', $transaction) }}" class="mt-4 grid gap-3">
                @csrf
                <label class="grid gap-1 text-sm">
                    <span class="font-medium text-slate-700">Reason for reversal</span>
                    <textarea name="reason" required rows="3" class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="Explain the posting error or adjustment reason.">{{ old('reason') }}</textarea>
                </label>
                <div><button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Request reversal</button></div>
            </form>
        @endif

        @if($pendingReversal)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                <div class="font-semibold">Requested by {{ $pendingReversal->requestedBy?->name }}</div>
                <div class="mt-1">{{ $pendingReversal->reason }}</div>
            </div>

            @if($canApproveReversal)
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <form method="POST" action="{{ route('transactions.reversal-requests.approve', $pendingReversal) }}" class="grid gap-2">
                        @csrf
                        <input name="comments" class="rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Approval note optional">
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve reversal</button>
                    </form>
                    <form method="POST" action="{{ route('transactions.reversal-requests.reject', $pendingReversal) }}" class="grid gap-2">
                        @csrf
                        <input name="comments" required class="rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Rejection reason">
                        <button class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Reject reversal</button>
                    </form>
                </div>
            @endif
        @endif
    </section>
</div>
@endsection
