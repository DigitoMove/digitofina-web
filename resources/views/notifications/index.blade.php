@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="df-card">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="df-muted">Messages for loan workflow, accounts, drawers, and client activity.</p>
                    <h1 class="mt-1 text-2xl font-semibold text-slate-950">Notifications</h1>
                </div>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800">
                        Mark all read
                    </button>
                </form>
            </div>
        </div>

        <div class="df-card divide-y divide-slate-100 overflow-hidden p-0">
            @forelse($notifications as $notification)
                @php
                    $data = $notification->data ?? [];
                    $levelClasses = [
                        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                        'warning' => 'bg-amber-50 text-amber-700 ring-amber-100',
                        'danger' => 'bg-red-50 text-red-700 ring-red-100',
                    ][$data['level'] ?? 'info'] ?? 'bg-brand-50 text-brand-700 ring-brand-100';
                @endphp

                <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center {{ $notification->read_at ? 'bg-white' : 'bg-brand-50/40' }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $levelClasses }}">
                                {{ ucfirst($data['category'] ?? 'system') }}
                            </span>
                            @unless($notification->read_at)
                                <span class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white">New</span>
                            @endunless
                            <span class="text-xs font-medium text-slate-500">{{ $notification->created_at?->diffForHumans() }}</span>
                        </div>
                        <h2 class="mt-2 text-base font-semibold text-slate-950">{{ $data['title'] ?? 'Notification' }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $data['message'] ?? '' }}</p>
                    </div>

                    <div class="flex flex-wrap gap-2 sm:justify-end">
                        @if(! empty($data['action_url']))
                            <a href="{{ $data['action_url'] }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                Open
                            </a>
                        @endif
                        @unless($notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                @csrf
                                <button class="rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-brand-800">Mark read</button>
                            </form>
                        @endunless
                    </div>
                </div>
            @empty
                <div class="p-10 text-center">
                    <h2 class="text-lg font-semibold text-slate-950">No notifications yet</h2>
                    <p class="mt-2 text-sm text-slate-500">Loan and account activity will appear here as it happens.</p>
                </div>
            @endforelse
        </div>

        {{ $notifications->links() }}
    </div>
@endsection
