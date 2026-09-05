<div class="mb-6 grid min-w-0 gap-4 xl:grid-cols-2">
    <section class="overflow-hidden rounded-xl border {{ $progress['overdue'] > 0 ? 'border-amber-200 bg-amber-50' : 'border-brand-200 bg-brand-50' }} p-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Current status</p>
        <h2 class="mt-2 text-2xl font-semibold text-slate-950">{{ $progress['status'] }}</h2>
        @if($progress['next'])
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div><p class="text-sm text-slate-600">{{ $progress['overdue'] > 0 ? 'Overdue installments' : 'Next installment' }}</p><p class="mt-1 break-words text-2xl font-semibold text-slate-950">{{ $money($progress['overdue'] ?: $progress['next']['schedule']->outstanding_amount) }}</p></div>
                <div><p class="text-sm text-slate-600">{{ $progress['overdue'] > 0 ? 'Oldest unpaid due date' : 'Due date' }}</p><p class="mt-1 text-lg font-semibold text-slate-950">{{ $progress['next']['schedule']->due_date->format('d M Y') }}</p><p class="text-xs text-slate-500">{{ $progress['next']['loan']->loan_number }}</p></div>
            </div>
            <a href="#repay-loan-{{ $progress['next']['loan']->id }}" class="df-action-primary mt-5">Make a repayment →</a>
        @else
            <p class="mt-4 text-sm text-slate-600">No unpaid installments are currently scheduled. Your loan details and statements are available below.</p>
            <a href="{{ route('member.statements') }}" class="df-action-secondary mt-5">View my statements</a>
        @endif
    </section>
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-semibold text-slate-950">Your repayment progress</h2><p class="mt-1 text-xs text-slate-500">Principal repaid across your loans</p></div><span class="text-3xl font-semibold text-brand-700">{{ $progress['percentage'] }}%</span></div>
        <div class="mt-5 h-3 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Principal repaid" aria-valuenow="{{ $progress['percentage'] }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand-600" style="width: {{ $progress['percentage'] }}%"></div></div>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Principal repaid</dt><dd class="mt-1 font-semibold text-brand-800">{{ $money($progress['principal_paid']) }}</dd></div><div><dt class="text-slate-500">Principal remaining</dt><dd class="mt-1 font-semibold text-slate-900">{{ $money($progress['principal_remaining']) }}</dd></div></dl>
        <p class="mt-4 text-xs text-slate-500">Confirmed payments only. Interest, fees, and penalties are shown separately in your loan balance.</p>
    </section>
</div>
<section class="mb-6 min-w-0 rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold text-slate-950">Repayment trend</h2><p class="mt-1 text-xs text-slate-500">Total confirmed repayments each month · last six months</p></div><a class="text-sm font-semibold text-brand-700" href="{{ route('member.statements') }}">Explore statements →</a></div>
    <div class="df-compact-grid mt-5 grid grid-cols-6 items-end gap-2 sm:gap-5" role="img" aria-label="Monthly repayments: {{ $progress['months']->map(fn ($month) => $month['period'].': '.$money($month['amount']))->implode('; ') }}">
        @foreach($progress['months'] as $month)
            <div class="min-w-0 text-center">
                <div class="flex h-24 items-end justify-center rounded-lg bg-slate-50"><div class="w-full max-w-20 rounded-t-md bg-brand-600" style="height: {{ $month['amount'] > 0 ? max(3, round(100 * $month['amount'] / $progress['maximum'])) : 0 }}%" title="{{ $month['period'] }}: {{ $money($month['amount']) }}"></div></div>
                <p class="mt-2 text-xs font-semibold text-slate-700">{{ $month['label'] }}</p><p class="mt-1 break-words text-[10px] tabular-nums text-slate-500">{{ number_format($month['amount']) }}</p>
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-slate-500">Amounts in {{ $client->organization?->currency ?: 'UGX' }}. Reversed payments do not count towards progress.</p>
</section>
