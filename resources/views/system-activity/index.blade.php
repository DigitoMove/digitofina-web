@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">System activity</h1>
        <p class="text-sm text-slate-500">Live staff presence and client visits. Online means active within the last {{ $summary['online_minutes'] }} minutes.</p>
    </div>
    <a href="{{ route('dashboard') }}" class="df-action-secondary">Back to dashboard</a>
</div>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
    @foreach([
        ['Active staff', $summary['active_staff']],
        ['Online now', $summary['online_staff']],
        ['Staff devices', $summary['active_devices']],
        ['Clients', $summary['total_clients']],
        ['Client visits', $summary['visiting_clients'].' / '.$summary['visit_days'].'d'],
    ] as [$label, $value])
        <div class="df-panel p-5">
            <div class="text-sm text-slate-500">{{ $label }}</div>
            <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $value }}</div>
        </div>
    @endforeach
</div>

<section class="df-panel mt-6 overflow-hidden">
    <div class="border-b border-slate-200 p-5">
        <h2 class="text-lg font-semibold text-slate-950">Online users</h2>
        <p class="text-sm text-slate-500">The latest browser session for each currently active user.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                <tr><th class="px-5 py-3">User</th><th class="px-5 py-3">Organization / branch</th><th class="px-5 py-3">Device</th><th class="px-5 py-3">Last activity</th><th class="px-5 py-3 text-right">Details</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($onlineUsers as $presence)
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-900">{{ $presence['user']->name }}<div class="text-xs font-normal text-slate-500">{{ $presence['user']->role?->name }}</div></td>
                        <td class="px-5 py-3 text-slate-600">{{ $presence['activity']->organization?->name ?? 'System' }} / {{ $presence['user']->branch?->name ?? 'All branches' }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ $presence['activity']->device_name }}<div class="text-xs text-slate-400">{{ $presence['device_count'] }} device(s) · {{ $presence['activity']->ip_address ?? 'IP unavailable' }}</div></td>
                        <td class="px-5 py-3 text-slate-600">{{ $presence['activity']->last_seen_at->diffForHumans() }}<div class="text-xs text-slate-400">{{ $presence['activity']->last_route }}</div></td>
                        <td class="px-5 py-3 text-right"><a href="{{ route('system-activity.users.show', $presence['user']) }}" class="df-action-secondary">View details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No users have been active in the last {{ $summary['online_minutes'] }} minutes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
