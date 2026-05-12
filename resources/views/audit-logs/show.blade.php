@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Audit Log Detail</h1>
    <p class="text-sm text-slate-500">{{ $log->action }} · {{ $log->created_at->format('d M Y H:i:s') }}</p>
</div>
<div class="grid gap-5 xl:grid-cols-2">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold">Context</h2>
        <dl class="mt-4 grid gap-3 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">User</dt><dd class="font-medium">{{ $log->user?->name ?? 'System' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Module</dt><dd class="font-medium">{{ $log->module ?? '-' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Record</dt><dd class="font-medium">{{ class_basename($log->record_type ?? $log->auditable_type) }} #{{ $log->record_id ?? $log->auditable_id }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">IP address</dt><dd class="font-medium">{{ $log->ip_address ?? '-' }}</dd></div>
        </dl>
    </section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold">User Agent</h2>
        <p class="mt-4 break-words text-sm text-slate-600">{{ $log->user_agent ?? '-' }}</p>
    </section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold">Old Values</h2>
        <pre class="mt-4 overflow-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($log->old_values, JSON_PRETTY_PRINT) }}</pre>
    </section>
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold">New Values</h2>
        <pre class="mt-4 overflow-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($log->new_values, JSON_PRETTY_PRINT) }}</pre>
    </section>
</div>
@endsection
