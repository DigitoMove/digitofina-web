@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">End of Day</h1>
        <p class="df-muted">{{ $branch->name }} · {{ $businessDate->format('d M Y') }} · {{ str($financialDay->status)->headline() }}</p>
    </div>
    <form method="GET" action="{{ route('end-of-day.index') }}" class="df-panel grid gap-2 p-2 md:grid-cols-[180px_180px_auto]">
        <select name="branch_id">
            @foreach($branches as $availableBranch)
                <option value="{{ $availableBranch->id }}" @selected($availableBranch->id === $branch->id)>{{ $availableBranch->name }}</option>
            @endforeach
        </select>
        <input type="date" name="business_date" value="{{ $businessDate->toDateString() }}">
        <button class="df-action-secondary">Load</button>
    </form>
</div>

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-coral-500/20 bg-coral-50 p-3 text-sm text-coral-700">{{ $errors->first() }}</div>
@endif

@php
    $cards = [
        ['Opening cash', $summary?->opening_cash ?? 0],
        ['Cash in', $summary?->cash_in ?? 0],
        ['Cash out', $summary?->cash_out ?? 0],
        ['Expected cash', $summary?->expected_cash ?? 0],
        ['Counted cash', $summary?->counted_cash ?? 0],
        ['Variance', $summary?->cash_variance ?? 0],
        ['Loan repayments', $summary?->loan_repayments ?? 0],
        ['Penalties applied', $summary?->penalties_applied ?? 0],
    ];
@endphp

<section class="df-panel mb-5 p-5">
    <div class="grid gap-3 md:grid-cols-5">
        @foreach ([['Open', 'open'], ['Reconcile', 'reconciled'], ['Process', 'generated'], ['Close', 'closed'], ['Lock', 'locked']] as $index => [$label, $status])
            @php
                $isComplete = match ($status) {
                    'open' => in_array($financialDay->status, ['open', 'closed', 'locked'], true),
                    'reconciled' => filled($reconciliation),
                    'generated' => filled($summary),
                    'closed' => in_array($financialDay->status, ['closed', 'locked'], true),
                    'locked' => $financialDay->status === 'locked',
                    default => false,
                };
                $isActive = $financialDay->status === $status || ($status === 'reconciled' && filled($reconciliation) && $financialDay->status === 'open');
            @endphp
            <div class="df-step {{ $isComplete ? 'is-complete' : '' }} {{ $isActive ? 'is-active' : '' }}">
                <span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">{{ $index + 1 }}</span>
                {{ $label }}
            </div>
        @endforeach
    </div>
</section>

<div data-df-tabs class="grid gap-5">
    <div class="df-tablist" role="tablist">
        <button type="button" class="df-tab" data-df-tab-target="summary">Daily Summary</button>
        <button type="button" class="df-tab" data-df-tab-target="reconcile">Cash Reconciliation</button>
        <button type="button" class="df-tab" data-df-tab-target="accounting">Accounting Summary</button>
        <button type="button" class="df-tab" data-df-tab-target="controls">Close & Lock</button>
    </div>

    <section data-df-tab-panel="summary" class="grid gap-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as [$label, $amount])
                <div class="df-panel df-hover-lift p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-xl font-semibold text-slate-950">UGX {{ number_format((float) $amount, 2) }}</div>
                </div>
            @endforeach
        </div>

        <div class="df-panel p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-slate-950">Operational Checks</h2>
                    <p class="df-muted">Teller sessions, loan arrears, reminders, and transaction activity for this branch day.</p>
                </div>
                <form method="POST" action="{{ route('end-of-day.process') }}">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                    <input type="hidden" name="business_date" value="{{ $businessDate->toDateString() }}">
                    <button class="df-action-secondary">Run Processing</button>
                </form>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-4">
                <div class="df-soft-panel p-4 text-sm"><div class="font-semibold">{{ number_format($summary?->teller_sessions_opened ?? 0) }}</div><div class="text-slate-500">Sessions opened</div></div>
                <div class="df-soft-panel p-4 text-sm"><div class="font-semibold">{{ number_format($summary?->teller_sessions_closed ?? 0) }}</div><div class="text-slate-500">Sessions closed</div></div>
                <div class="df-soft-panel p-4 text-sm"><div class="font-semibold">{{ number_format($summary?->overdue_installments ?? 0) }}</div><div class="text-slate-500">Overdue installments</div></div>
                <div class="df-soft-panel p-4 text-sm"><div class="font-semibold">{{ number_format($summary?->reminders_queued ?? 0) }}</div><div class="text-slate-500">Reminders queued</div></div>
            </div>
        </div>
    </section>

    <section data-df-tab-panel="reconcile" class="grid gap-5 xl:grid-cols-[1fr_0.9fr]">
        <form method="POST" action="{{ route('end-of-day.reconcile') }}" class="df-panel p-5">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <input type="hidden" name="business_date" value="{{ $businessDate->toDateString() }}">
            <h2 class="font-semibold text-slate-950">Branch Cash Count</h2>
            <p class="df-muted">Count physical cash after all teller drawers have been closed.</p>
            <div class="mt-4 grid gap-3">
                <label class="text-sm font-semibold text-slate-700">Counted Cash
                    <input class="mt-1" name="counted_cash" type="number" step="0.01" value="{{ old('counted_cash', $reconciliation?->counted_cash ?? $summary?->expected_cash ?? 0) }}">
                </label>
                <label class="text-sm font-semibold text-slate-700">Notes
                    <textarea class="mt-1" name="notes" rows="3">{{ old('notes', $reconciliation?->notes) }}</textarea>
                </label>
                <button class="df-action-primary">Reconcile Cash</button>
            </div>
        </form>

        <div class="df-panel p-5">
            <h2 class="font-semibold text-slate-950">Reconciliation Result</h2>
            <dl class="mt-4 grid gap-3 text-sm">
                <div class="df-soft-panel flex justify-between gap-4 p-3"><dt>Expected</dt><dd class="font-semibold">UGX {{ number_format((float) ($reconciliation?->expected_cash ?? $summary?->expected_cash ?? 0), 2) }}</dd></div>
                <div class="df-soft-panel flex justify-between gap-4 p-3"><dt>Counted</dt><dd class="font-semibold">UGX {{ number_format((float) ($reconciliation?->counted_cash ?? 0), 2) }}</dd></div>
                <div class="df-soft-panel flex justify-between gap-4 p-3"><dt>Variance</dt><dd class="font-semibold {{ (float) ($reconciliation?->variance ?? 0) === 0.0 ? 'text-forest-700' : 'text-coral-700' }}">UGX {{ number_format((float) ($reconciliation?->variance ?? 0), 2) }}</dd></div>
                <div class="df-soft-panel flex justify-between gap-4 p-3"><dt>Status</dt><dd class="font-semibold">{{ $reconciliation ? str($reconciliation->status)->headline() : 'Pending' }}</dd></div>
            </dl>
        </div>
    </section>

    <section data-df-tab-panel="accounting" class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Daily Accounting Summary</h2>
        <p class="df-muted">Journal-line totals grouped by chart of account.</p>
        <div class="mt-4 overflow-hidden rounded-lg border border-slate-200">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr><th>Code</th><th>Ledger</th><th>Debit</th><th>Credit</th><th>Net Movement</th><th>Lines</th></tr>
                </thead>
                <tbody>
                    @forelse($accountingSummaries as $row)
                        <tr class="border-t border-slate-200">
                            <td class="font-medium">{{ $row->account_code ?? 'N/A' }}</td>
                            <td>{{ $row->account_name }}</td>
                            <td>UGX {{ number_format((float) $row->debit_total, 2) }}</td>
                            <td>UGX {{ number_format((float) $row->credit_total, 2) }}</td>
                            <td>UGX {{ number_format((float) $row->net_movement, 2) }}</td>
                            <td>{{ number_format($row->line_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4 text-slate-500">No accounting summary generated yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section data-df-tab-panel="controls" class="grid gap-5 xl:grid-cols-2">
        <form method="POST" action="{{ route('end-of-day.close') }}" class="df-panel p-5">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <input type="hidden" name="business_date" value="{{ $businessDate->toDateString() }}">
            <h2 class="font-semibold text-slate-950">Close Business Day</h2>
            <p class="df-muted">Requires all teller sessions to be closed. Closing runs summaries, overdue checks, penalties, and reminders.</p>
            <div class="mt-4 grid gap-3">
                <input name="counted_cash" type="number" step="0.01" value="{{ old('counted_cash', $reconciliation?->counted_cash ?? $summary?->expected_cash ?? 0) }}">
                <textarea name="notes" rows="3" placeholder="Closure notes">{{ old('notes', $reconciliation?->notes) }}</textarea>
                <button class="df-action-primary">Close Day</button>
            </div>
        </form>

        <form method="POST" action="{{ route('end-of-day.lock') }}" class="df-panel p-5">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <input type="hidden" name="business_date" value="{{ $businessDate->toDateString() }}">
            <h2 class="font-semibold text-slate-950">Lock Completed Day</h2>
            <p class="df-muted">Locked days reject financial postings and unauthorized backdated activity for this branch.</p>
            <div class="mt-4 df-soft-panel p-4 text-sm">
                Current status: <span class="font-semibold">{{ str($financialDay->status)->headline() }}</span>
            </div>
            <button class="df-action-danger mt-4">Lock Day</button>
        </form>
    </section>
</div>
@endsection
