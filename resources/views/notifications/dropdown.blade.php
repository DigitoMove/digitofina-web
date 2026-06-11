@php
    $unreadNotificationsCount = auth()->user()->unreadNotifications()->count();
    $recentNotifications = auth()->user()->notifications()->latest()->limit(5)->get();
@endphp

<details class="group/notifications relative">
    <summary class="grid h-10 w-10 cursor-pointer list-none place-items-center rounded-xl border border-slate-200 bg-slate-50 text-slate-700 shadow-sm transition hover:bg-brand-50 hover:text-brand-900 [&::-webkit-details-marker]:hidden" aria-label="Notifications">
        <span class="relative">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M15 17H9m9-6a6 6 0 1 0-12 0c0 7-3 6-3 8h18c0-2-3-1-3-8M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
            @if($unreadNotificationsCount > 0)
                <span class="absolute -right-2 -top-2 grid min-h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[0.65rem] font-bold leading-none text-white">
                    {{ $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount }}
                </span>
            @endif
        </span>
    </summary>

    <div class="fixed left-4 right-4 top-16 z-[80] mt-2 max-h-[calc(100vh-5rem)] w-auto overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl ring-1 ring-slate-950/5 sm:absolute sm:left-auto sm:right-0 sm:top-auto sm:mt-3 sm:max-h-none sm:w-[min(22rem,calc(100vw-2rem))]">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <div>
                <div class="font-semibold text-slate-950">Notifications</div>
                <div class="text-xs text-slate-500">{{ $unreadNotificationsCount }} unread</div>
            </div>
            <div class="flex items-center gap-3">
                @if($unreadNotificationsCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button class="text-xs font-semibold text-slate-500 hover:text-brand-900">Mark all</button>
                    </form>
                @endif
                <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-brand-700 hover:text-brand-900">View all</a>
            </div>
        </div>

        <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
            @forelse($recentNotifications as $notification)
                @php
                    $data = $notification->data ?? [];
                    $actionUrl = route('notifications.open', $notification);
                @endphp
                <a href="{{ $actionUrl }}" class="block px-4 py-3 transition hover:bg-slate-50 {{ $notification->read_at ? '' : 'bg-brand-50/50' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-slate-950">{{ $data['title'] ?? 'Notification' }}</div>
                            <div class="mt-1 line-clamp-2 text-xs leading-5 text-slate-600">{{ $data['message'] ?? '' }}</div>
                        </div>
                        @unless($notification->read_at)
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-700"></span>
                        @endunless
                    </div>
                    <div class="mt-2 text-[0.7rem] font-medium text-slate-400">{{ $notification->created_at?->diffForHumans() }}</div>
                </a>
            @empty
                <div class="px-4 py-6 text-sm text-slate-500">No notifications yet.</div>
            @endforelse
        </div>
    </div>
</details>
