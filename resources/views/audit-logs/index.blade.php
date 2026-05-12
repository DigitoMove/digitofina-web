@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="text-2xl font-semibold text-slate-950">Audit Logs</h1>
    <p class="text-sm text-slate-500">Security and operational trace of important system actions.</p>
</div>
<form class="mb-4 grid gap-3 md:grid-cols-3">
    <input name="action" value="{{ request('action') }}" class="rounded border-slate-300" placeholder="Action contains">
    <input name="module" value="{{ request('module') }}" class="rounded border-slate-300" placeholder="Module">
    <button class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter Logs</button>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Action</th><th>User</th><th>Record</th><th>IP</th><th>Time</th><th></th></tr></thead>
        <tbody>
            @foreach ($logs as $log)
                <tr class="border-t">
                    <td class="p-3 font-medium text-slate-950">{{ $log->action }}<div class="text-slate-500">{{ $log->module ?? '-' }}</div></td>
                    <td>{{ $log->user?->name ?? 'System' }}</td>
                    <td>{{ class_basename($log->record_type ?? $log->auditable_type) }} #{{ $log->record_id ?? $log->auditable_id }}</td>
                    <td>{{ $log->ip_address ?? '-' }}</td>
                    <td>{{ $log->created_at->format('d M Y H:i') }}</td>
                    <td><a href="{{ route('audit-logs.show', $log) }}" class="font-medium text-blue-700">View</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
