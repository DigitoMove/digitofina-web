@extends('layouts.app')

@section('title', 'Operations Health')
@section('content')
<div class="space-y-5">
    <section class="df-panel p-5">
        <h1 class="text-xl font-bold text-slate-950">Operations Health</h1>
        <p class="df-muted">Recorded task outcomes and cash reconciliation exceptions. Times are {{ config('app.timezone') }}. This page does not change balances or retry financial postings.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border p-4">
                <h2 class="font-semibold">Scheduler heartbeat</h2>
                <p>{{ $schedulerHealthy ? 'Recent heartbeat received' : 'Attention: heartbeat missing or older than five minutes' }}</p>
                <p class="df-muted">Last seen: {{ $heartbeat?->format('d M Y H:i:s') ?? 'Not yet recorded' }}</p>
            </div>
            @if($global)
                <div class="rounded-lg border p-4">
                    <h2 class="font-semibold">Backup and queue checks</h2>
                    <p>{{ $backupConfigured ? 'Backup command available; a successful restore is not yet verified.' : 'Action required: no application backup command is installed. External backups must be verified separately.' }}</p>
                    <p class="df-muted">Failed queue jobs: {{ $failedJobs ?? 'Unavailable for this queue configuration' }}</p>
                </div>
            @endif
        </div>
    </section>
    @if($global)
        <section class="grid gap-4 md:grid-cols-3">
            @foreach($tasks as $task)
                <article class="df-panel min-w-0 p-5">
                    <h2 class="font-semibold">{{ $task['label'] }}</h2>
                    <span class="df-badge">{{ ucfirst($task['status']) }}</span>
                    <p class="mt-3 text-sm">Last success: {{ $task['last_success']?->format('d M Y H:i') ?? 'Not verified' }}</p>
                    <p class="df-muted">Next scheduled: {{ $task['next_due']->format('d M Y H:i') }}</p>
                </article>
            @endforeach
        </section>
        <p class="df-muted">Daily tasks allow one hour to finish before being marked overdue. Running tasks older than 20 minutes need investigation. Unverified means no successful run has been recorded; it does not mean the task succeeded.</p>
    @endif
    <section class="df-panel p-5">
        <h2 class="font-semibold">Recent task runs</h2>
        <p class="df-muted">Queued jobs are recorded when execution starts, not when they are dispatched. End-of-day outcomes are recorded per branch.</p>
        <div class="mt-4 space-y-3">
            @forelse($runs as $run)
                <article class="rounded-lg border p-4">
                    <div class="flex flex-wrap justify-between gap-2">
                        <h3 class="font-semibold">{{ str($run->task)->headline() }} @if($run->branch_id) · Branch #{{ $run->branch_id }} @endif</h3>
                        <span class="df-badge">{{ $run->status === 'running' && $run->started_at->lt(now()->subMinutes(20)) ? 'Stalled / investigate' : ucfirst($run->status) }}</span>
                    </div>
                    <p class="df-muted">Started {{ $run->started_at->format('d M Y H:i:s') }} · Finished {{ $run->finished_at?->format('d M Y H:i:s') ?? 'Not recorded' }}</p>
                    @if($run->failure)<p class="text-sm text-red-700">{{ $run->failure }} — ask the system administrator to check server logs for run #{{ $run->id }}.</p>@endif
                    @foreach($run->summary ?? [] as $label => $value)
                        <span class="mr-3 inline-block break-all text-sm">{{ str($label)->headline() }}: {{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : 'Recorded') }}</span>
                    @endforeach
                </article>
            @empty
                <p class="df-muted">No execution history recorded for this scope yet.</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </section>
    <section class="df-panel p-5">
        <h2 class="font-semibold">Recorded cash variances · last 30 days</h2>
        <p class="df-muted">Up to 20 recent non-zero reconciliations. These are recorded differences, not a complete ledger audit or automatic repair.</p>
        <div class="mt-4 space-y-3">
            @forelse($variances as $variance)
                <article class="flex flex-wrap justify-between gap-3 rounded-lg border p-4">
                    <div><h3 class="font-semibold">{{ $variance->branch?->name ?? 'Branch #'.$variance->branch_id }}</h3><p class="df-muted">{{ $variance->business_date->format('d M Y') }} · {{ ucfirst($variance->status) }}</p></div>
                    <div class="text-sm"><p>Expected: {{ number_format((float) $variance->expected_cash, 2) }}</p><p>Counted: {{ number_format((float) $variance->counted_cash, 2) }}</p><p class="font-semibold">Variance: {{ number_format((float) $variance->variance, 2) }}</p></div>
                </article>
            @empty
                <p class="df-muted">No recorded non-zero cash variances in this scope and period. Missing reconciliations are not proof of balanced cash.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
