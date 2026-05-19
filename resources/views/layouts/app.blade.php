<!doctype html>
<html lang="en">
<head>
    @php
        $seo = \App\Support\SeoMetadata::forRequest(request(), array_filter([
            'title' => $title ?? null,
            'description' => $description ?? null,
        ], fn ($value) => filled($value)));
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-seo-meta :seo="$seo" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
    <div class="min-h-screen bg-slate-100 lg:flex">
        <div class="fixed inset-0 z-40 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden" data-sidebar-backdrop></div>

        <aside id="app-sidebar" class="fixed inset-y-0 left-0 z-50 flex w-80 max-w-[86vw] -translate-x-full flex-col border-r border-slate-200 bg-white shadow-2xl transition-transform duration-300 ease-out lg:fixed lg:inset-y-0 lg:w-72 lg:max-w-none lg:translate-x-0 lg:shadow-sm" data-sidebar>
            <div class="flex h-16 items-center justify-between border-b border-slate-200 px-5">
                <div class="flex min-w-0 items-center">
                    <img src="{{ asset('brand/digitofina-mark.svg') }}" alt="DigitoFina" class="h-10 w-10 rounded-lg shadow-sm ring-1 ring-slate-200">
                    <div class="ml-3 min-w-0">
                        <div class="truncate font-semibold tracking-tight text-slate-950">DigitoFina</div>
                        <div class="truncate text-xs text-slate-500">Microfinance operating core</div>
                    </div>
                </div>
                <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 lg:hidden" data-sidebar-close aria-label="Close navigation">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>
            <nav class="grid flex-1 content-start gap-1 overflow-y-auto p-3 text-sm lg:max-h-[calc(100vh-8rem)]">
                @php
                    $user = auth()->user();
                    $isRouteActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*');
                    $canManageOrganization = $user->canPerform('manage-organization') || $user->hasRole('super-admin') || $user->hasRole('supervisor') || $user->hasRole('organization-admin');
                    $navGroups = $user->isMember() ? [] : [
                        [
                            'label' => 'Clients',
                            'icon' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0',
                            'items' => [
                                ['Clients', 'clients.index', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0'],
                                ['Client Groups', 'client-groups.index', 'M7 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M17 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M3 21a6 6 0 0 1 8-5.7 M13 15.3A6 6 0 0 1 21 21'],
                            ],
                        ],
                        [
                            'label' => 'Loans',
                            'icon' => 'M4 6h16v12H4z M8 10h8 M8 14h5',
                            'items' => [
                                ['Loans', 'loans.index', 'M4 6h16v12H4z M8 10h8 M8 14h5'],
                                ['Arrears', 'arrears.index', 'M12 8v5l3 3 M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0z'],
                                ['Loan Products', 'loan-products.index', 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                            ],
                        ],
                        [
                            'label' => 'Operations',
                            'icon' => 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10',
                            'items' => [
                                ['Teller Drawer', 'teller.index', 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10'],
                                ['Non-Cash Transactions', 'non-cash.index', 'M7 7h10M7 12h10M7 17h6 M4 5h16v14H4z'],
                                ['End of Day', 'end-of-day.index', 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5'],
                                ['Data Imports', 'imports.index', 'M12 3v12m0 0 4-4m-4 4-4-4 M5 19h14 M5 15v4 M19 15v4'],
                            ],
                        ],
                        [
                            'label' => 'Accounts',
                            'icon' => 'M3 7h18v12H3z M7 7V5h10v2',
                            'items' => [
                                ['Accounts Register', 'accounts.index', 'M3 7h18v12H3z M7 7V5h10v2'],
                                ['Savings Products', 'savings-products.index', 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                                ['Savings Transfer', 'savings-transfers.create', 'M7 7h12m0 0-4-4m4 4-4 4 M17 17H5m0 0 4-4m-4 4 4 4'],
                            ],
                        ],
                    ];
                    $adminItems = $user->isMember() ? [] : array_values(array_filter([
                        $canManageOrganization ? ['Organization', 'organization.current', 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5'] : null,
                        $user->canPerform('manage-branches') ? ['Branches', 'branches.index', 'M4 20V8l8-4 8 4v12 M9 20v-6h6v6'] : null,
                        $user->canPerform('manage-users') ? ['Users', 'users.index', 'M16 11a4 4 0 1 0-8 0 M4 21a8 8 0 0 1 16 0'] : null,
                        $user->canPerform('manage-roles') ? ['Roles', 'roles.index', 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z'] : null,
                        $user->canPerform('manage-settings') ? ['Settings', 'settings.index', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z M4 12h2 M18 12h2 M12 4v2 M12 18v2'] : null,
                        $user->canPerform('view-audit-logs') ? ['Audit Logs', 'audit-logs.index', 'M5 4h14v16H5z M9 8h6 M9 12h6 M9 16h3'] : null,
                    ]));
                @endphp

                @if($user->isMember())
                    <a href="{{ route('member.dashboard') }}" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium transition {{ $isRouteActive('member.dashboard') ? 'bg-brand-50 text-brand-900 ring-1 ring-brand-200 shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-900' }}">
                        <span class="grid h-8 w-8 place-items-center rounded-lg transition group-hover:scale-105 {{ $isRouteActive('member.dashboard') ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 5h16v14H4z M8 9h8 M8 13h8 M8 17h4"/></svg>
                        </span>
                        My Progress
                    </a>
                @else
                    <a href="{{ route('dashboard') }}" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium transition {{ $isRouteActive('dashboard') ? 'bg-brand-50 text-brand-900 ring-1 ring-brand-200 shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-900' }}">
                        <span class="grid h-8 w-8 place-items-center rounded-lg transition group-hover:scale-105 {{ $isRouteActive('dashboard') ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 4h16v6H4z M4 14h7v6H4z M15 14h5v6h-5z"/></svg>
                        </span>
                        Dashboard
                    </a>

                    @foreach($navGroups as $group)
                        @php($groupActive = collect($group['items'])->contains(fn ($item) => $isRouteActive($item[1])))
                        <details class="group/nav rounded-lg" {{ $groupActive ? 'open' : '' }}>
                            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2.5 font-medium text-slate-600 transition hover:bg-slate-50 hover:text-brand-900 [&::-webkit-details-marker]:hidden {{ $groupActive ? 'bg-brand-50 text-brand-900 ring-1 ring-brand-200 shadow-sm' : '' }}">
                                <span class="grid h-8 w-8 place-items-center rounded-lg transition {{ $groupActive ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $group['icon'] }}"/></svg>
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>
                                <svg class="h-4 w-4 text-slate-400 transition group-open/nav:rotate-180" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </summary>
                            <div class="ml-4 mt-1 grid gap-1 border-l border-slate-200 pl-3">
                                @foreach($group['items'] as [$label, $route, $icon])
                                    <a href="{{ route($route) }}" class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $isRouteActive($route) ? 'bg-brand-50 text-brand-900' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-900' }}">
                                        <span class="grid h-7 w-7 place-items-center rounded-lg transition {{ $isRouteActive($route) ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                                        </span>
                                        <span class="truncate">{{ $label }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach

                    <a href="{{ route('reports.index') }}" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium transition {{ $isRouteActive('reports.index') ? 'bg-brand-50 text-brand-900 ring-1 ring-brand-200 shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-900' }}">
                        <span class="grid h-8 w-8 place-items-center rounded-lg transition group-hover:scale-105 {{ $isRouteActive('reports.index') ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h4"/></svg>
                        </span>
                        Reports
                    </a>

                    @if($adminItems !== [])
                        @php($adminActive = collect($adminItems)->contains(fn ($item) => $isRouteActive($item[1])))
                        <details class="group/nav mt-2 rounded-lg border-t border-slate-200 pt-2" {{ $adminActive ? 'open' : '' }}>
                            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2.5 font-medium text-slate-600 transition hover:bg-sun-50 hover:text-sun-600 [&::-webkit-details-marker]:hidden {{ $adminActive ? 'bg-sun-50 text-sun-600 ring-1 ring-amber-100 shadow-sm' : '' }}">
                                <span class="grid h-8 w-8 place-items-center rounded-lg transition {{ $adminActive ? 'bg-sun-400 text-slate-950' : 'bg-sun-50 text-sun-600' }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z M4 12h2 M18 12h2 M12 4v2 M12 18v2"/></svg>
                                </span>
                                <span class="min-w-0 flex-1 truncate">Administration</span>
                                <svg class="h-4 w-4 text-slate-400 transition group-open/nav:rotate-180" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </summary>
                            <div class="ml-4 mt-1 grid gap-1 border-l border-slate-200 pl-3">
                                @foreach ($adminItems as [$label, $route, $icon])
                                    <a href="{{ route($route) }}" class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $isRouteActive($route) ? 'bg-sun-50 text-sun-600' : 'text-slate-600 hover:bg-sun-50 hover:text-sun-600' }}">
                                        <span class="grid h-7 w-7 place-items-center rounded-lg transition {{ $isRouteActive($route) ? 'bg-sun-400 text-slate-950' : 'bg-sun-50 text-sun-600' }}">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                                        </span>
                                        <span class="truncate">{{ $label }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endif
            </nav>
            <div class="mx-3 mb-3 mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                <div class="font-semibold text-slate-800">Operational mode</div>
                <div class="mt-1">{{ auth()->user()->role?->name ?? 'User' }} · {{ now()->format('d M Y') }}</div>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col lg:pl-72">
            <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 text-slate-900 shadow-sm backdrop-blur">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6">
                    <button type="button" class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-slate-200 bg-slate-50 text-slate-700 shadow-sm transition hover:bg-brand-50 hover:text-brand-900 lg:hidden" data-sidebar-open aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    @unless(auth()->user()->isMember())
                        <form action="{{ route('clients.index') }}" class="hidden flex-1 sm:block">
                            <input name="search" class="h-11 w-full max-w-xl rounded-xl border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-4 focus:ring-brand-100" placeholder="Search client, phone, account or national ID">
                        </form>
                    @else
                        <div class="hidden flex-1 text-sm font-medium text-slate-500 sm:block">Member self-service portal</div>
                    @endunless
                    <div class="flex items-center gap-3 text-sm">
                        @php
                            $unreadNotificationsCount = auth()->user()->unreadNotifications()->count();
                            $recentNotifications = auth()->user()->notifications()->limit(5)->get();
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
                            <div class="absolute right-0 mt-3 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl ring-1 ring-slate-950/5">
                                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                    <div>
                                        <div class="font-semibold text-slate-950">Notifications</div>
                                        <div class="text-xs text-slate-500">{{ $unreadNotificationsCount }} unread</div>
                                    </div>
                                    <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-brand-700 hover:text-brand-900">View all</a>
                                </div>
                                <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
                                    @forelse($recentNotifications as $notification)
                                        @php($data = $notification->data ?? [])
                                        <a href="{{ $data['action_url'] ?? route('notifications.index') }}" class="block px-4 py-3 transition hover:bg-slate-50 {{ $notification->read_at ? '' : 'bg-brand-50/50' }}">
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
                        <span class="hidden text-slate-500 md:inline">{{ auth()->user()->branch?->name }}</span>
                        <a href="{{ route('profile.edit') }}" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 hover:bg-brand-50">{{ auth()->user()->name }}</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg bg-slate-900 px-3 py-1.5 font-semibold text-white hover:bg-brand-800">Logout</button></form>
                    </div>
                </div>
            </header>

            <main class="df-page flex-1 p-4 sm:p-6">
                <div class="fixed right-4 top-20 z-50 grid w-[min(24rem,calc(100vw-2rem))] gap-3">
                    @if (session('status'))
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg">{{ session('status') }}</div>
                    @endif
                    @if (session('warning'))
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 shadow-lg">{{ session('warning') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 shadow-lg">{{ $errors->first() }}</div>
                    @endif
                </div>
                {{ $slot ?? '' }}
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
