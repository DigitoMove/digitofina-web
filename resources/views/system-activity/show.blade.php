@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $trackedUser->name }}</h1>
        <p class="text-sm text-slate-500">{{ $trackedUser->email }} · {{ $trackedUser->role?->name }} · {{ $trackedUser->organization?->name ?? 'System' }}</p>
    </div>
    <a href="{{ route('system-activity.index') }}" class="df-action-secondary">Back to activity</a>
</div>

<section class="df-panel overflow-hidden">
    <div class="border-b border-slate-200 p-5">
        <h2 class="text-lg font-semibold text-slate-950">Sessions and devices</h2>
        <p class="text-sm text-slate-500">{{ $activities->pluck('device_key')->unique()->count() }} device(s) recorded. “First seen” is preserved for the session.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500"><tr><th class="px-5 py-3">Device</th><th class="px-5 py-3">IP / route</th><th class="px-5 py-3">First seen</th><th class="px-5 py-3">Last seen</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($activities as $activity)
                    <tr><td class="px-5 py-3 font-medium text-slate-900">{{ $activity->device_name }}<div class="text-xs font-normal text-slate-500">{{ $activity->platform }}</div></td><td class="px-5 py-3 text-slate-600">{{ $activity->ip_address ?? 'Unavailable' }}<div class="text-xs text-slate-400">{{ $activity->last_route }}</div></td><td class="px-5 py-3 text-slate-600">{{ $activity->first_seen_at->format('d M Y H:i') }}</td><td class="px-5 py-3 text-slate-600">{{ $activity->last_seen_at->diffForHumans() }}<div class="text-xs text-slate-400">{{ $activity->last_seen_at->format('d M Y H:i') }}</div></td></tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">No activity has been recorded for this user.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
