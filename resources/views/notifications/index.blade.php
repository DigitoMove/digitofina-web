@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-5xl space-y-5">
        <section class="df-panel p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="df-muted">Loan workflow, accounts, drawers, client activity, and system alerts.</p>
                    <h1 class="df-title mt-1">Notifications</h1>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="flex gap-2">
                        <span class="df-badge">{{ $unreadCount }} unread</span>
                        <span class="df-badge">{{ $readCount }} read</span>
                    </div>
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button @disabled($unreadCount === 0) class="df-action-primary disabled:pointer-events-none disabled:bg-slate-300">
                            Mark all read
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section class="df-panel divide-y divide-slate-100 overflow-hidden">
            @forelse($notifications as $notification)
                @php
                    $data = $notification->data ?? [];
                    $levelClasses = [
                        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                        'warning' => 'bg-amber-50 text-amber-700 ring-amber-100',
                        'danger' => 'bg-red-50 text-red-700 ring-red-100',
                    ][$data['level'] ?? 'info'] ?? 'bg-brand-50 text-brand-700 ring-brand-100';
                @endphp

                <article class="grid gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center {{ $notification->read_at ? 'bg-white' : 'bg-brand-50/50' }}">
                    <a href="{{ route('notifications.open', $notification) }}" class="group min-w-0 rounded-lg p-1 -m-1 hover:bg-white/70">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $levelClasses }}">
                                {{ ucfirst($data['category'] ?? 'system') }}
                            </span>
                            @unless($notification->read_at)
                                <span class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white">New</span>
                            @endunless
                            <span class="text-xs font-medium text-slate-500">{{ $notification->created_at?->diffForHumans() }}</span>
                        </div>
                        <h2 class="mt-2 text-base font-semibold text-slate-950 group-hover:text-brand-900">{{ $data['title'] ?? 'Notification' }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $data['message'] ?? '' }}</p>
                    </a>

                    <div class="flex flex-wrap gap-2 sm:justify-end">
                        <a href="{{ route('notifications.open', $notification) }}" class="df-action-secondary">
                            Open
                        </a>
                        @unless($notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                @csrf
                                <button class="df-action-primary">Mark read</button>
                            </form>
                        @endunless
                    </div>
                </article>
            @empty
                <div class="p-10 text-center">
                    <h2 class="text-lg font-semibold text-slate-950">No notifications yet</h2>
                    <p class="mt-2 text-sm text-slate-500">Loan and account activity will appear here as it happens.</p>
                </div>
            @endforelse
        </section>

        {{ $notifications->links() }}
    </div>
@endsection
