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
<body class="bg-brand-50 text-slate-900 antialiased">
    <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,#f3e8ff_0,#faf5ff_22rem,transparent_38rem),linear-gradient(180deg,#fdf4ff_0%,#f8fafc_34rem,#f8fafc_100%)] lg:flex">
        <aside class="border-r border-brand-100/80 bg-white/90 shadow-xl shadow-brand-200/40 backdrop-blur lg:fixed lg:inset-y-0 lg:w-64">
            <div class="flex h-16 items-center border-b border-brand-100/80 px-5">
                <img src="{{ asset('brand/digitofina-mark.svg') }}" alt="DigitoFina" class="h-11 w-11 rounded-2xl shadow-sm ring-4 ring-brand-100">
                <div class="ml-3">
                    <div class="font-semibold text-slate-950">DigitoFina</div>
                    <div class="text-xs text-brand-700">Financial Management Core</div>
                </div>
            </div>
            <nav class="grid gap-1 p-3 text-sm">
                @php
                    $user = auth()->user();
                    $items = $user->isMember()
                        ? [
                            ['My Progress', 'member.dashboard', 'M4 5h16v14H4z M8 9h8 M8 13h8 M8 17h4'],
                        ]
                        : [
                            ['Dashboard', 'dashboard', 'M4 4h16v6H4z M4 14h7v6H4z M15 14h5v6h-5z'],
                            ['Clients', 'clients.index', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0'],
                            ['Client Groups', 'client-groups.index', 'M7 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M17 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M3 21a6 6 0 0 1 8-5.7 M13 15.3A6 6 0 0 1 21 21'],
                            ['Accounts', 'accounts.index', 'M3 7h18v12H3z M7 7V5h10v2'],
                            ['Savings Products', 'savings-products.index', 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                            ['Savings Accounts', 'savings-accounts.index', 'M4 7h16v10H4z M7 11h10 M7 15h6'],
                            ['Savings Transfer', 'savings-transfers.create', 'M7 7h12m0 0-4-4m4 4-4 4 M17 17H5m0 0 4-4m-4 4 4 4'],
                            ['Loan Products', 'loan-products.index', 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                            ['Loans', 'loans.index', 'M4 6h16v12H4z M8 10h8 M8 14h5'],
                            ['Arrears', 'arrears.index', 'M12 8v5l3 3 M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0z'],
                            ['Teller Drawer', 'teller.index', 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10'],
                            ['Reports', 'reports.index', 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h4'],
                        ];
                    $adminItems = [
                        ['Organization', 'organization.edit', $user->organization, 'manage-organization', 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5'],
                        ['Branches', 'branches.index', null, 'manage-branches', 'M4 20V8l8-4 8 4v12 M9 20v-6h6v6'],
                        ['Users', 'users.index', null, 'manage-users', 'M16 11a4 4 0 1 0-8 0 M4 21a8 8 0 0 1 16 0'],
                        ['Roles', 'roles.index', null, 'manage-roles', 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z'],
                        ['Settings', 'settings.index', null, 'manage-settings', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z M4 12h2 M18 12h2 M12 4v2 M12 18v2'],
                        ['Audit Logs', 'audit-logs.index', null, 'view-audit-logs', 'M5 4h14v16H5z M9 8h6 M9 12h6 M9 16h3'],
                    ];
                @endphp
                @foreach ($items as [$label, $route, $icon])
                    <a href="{{ route($route) }}" class="flex items-center gap-3 rounded-2xl px-3 py-2.5 font-medium transition {{ request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*') ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-100 shadow-sm' : 'text-slate-600 hover:bg-brand-50 hover:text-brand-900' }}">
                        <span class="grid h-8 w-8 place-items-center rounded-xl {{ request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*') ? 'bg-gradient-to-br from-brand-700 to-orchid-500 text-white' : 'bg-brand-50 text-brand-500' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                        </span>
                        {{ $label }}
                    </a>
                @endforeach
                @unless($user->isMember())
                    <div class="mt-3 border-t border-brand-100/80 pt-3 text-xs font-semibold uppercase tracking-wide text-brand-300">Administration</div>
                    @foreach ($adminItems as [$label, $route, $parameter, $permission, $icon])
                        @if ($user->canPerform($permission) && ($route !== 'organization.edit' || $parameter))
                            <a href="{{ $parameter ? route($route, $parameter) : route($route) }}" class="flex items-center gap-3 rounded-2xl px-3 py-2.5 font-medium transition {{ request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*') ? 'bg-orchid-50 text-orchid-700 ring-1 ring-brand-100 shadow-sm' : 'text-slate-600 hover:bg-orchid-50 hover:text-orchid-700' }}">
                                <span class="grid h-8 w-8 place-items-center rounded-xl {{ request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*') ? 'bg-gradient-to-br from-orchid-500 to-brand-700 text-white' : 'bg-orchid-50 text-orchid-500' }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                                </span>
                                {{ $label }}
                            </a>
                        @endif
                    @endforeach
                @endunless
            </nav>
            <div class="mx-3 mt-2 rounded-3xl border border-brand-100 bg-gradient-to-br from-brand-50 to-orchid-50 p-3 text-xs text-brand-900">
                <div class="font-semibold text-slate-800">Operational mode</div>
                <div class="mt-1">{{ auth()->user()->role?->name ?? 'User' }} · {{ now()->format('d M Y') }}</div>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col lg:pl-64">
            <header class="sticky top-0 z-20 border-b border-brand-950 bg-brand-950 text-white shadow-sm">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6">
                    @unless(auth()->user()->isMember())
                        <form action="{{ route('clients.index') }}" class="hidden flex-1 sm:block">
                            <input name="search" class="h-10 w-full max-w-xl rounded-2xl border-0 bg-white/95 px-4 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-4 focus:ring-brand-300/40" placeholder="Search client, phone, account or national ID">
                        </form>
                    @else
                        <div class="hidden flex-1 text-sm font-medium text-brand-100 sm:block">Member self-service portal</div>
                    @endunless
                    <div class="flex items-center gap-3 text-sm">
                        <span class="hidden text-brand-100 md:inline">{{ auth()->user()->branch?->name }}</span>
                        <a href="{{ route('profile.edit') }}" class="rounded-xl border border-white/15 bg-white/10 px-3 py-1.5 hover:bg-white/15">{{ auth()->user()->name }}</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-xl bg-orchid-400 px-3 py-1.5 font-semibold text-white hover:bg-orchid-500">Logout</button></form>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6">
                @if (session('status'))
                    <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-sm">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-sm">{{ $errors->first() }}</div>
                @endif
                {{ $slot ?? '' }}
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
