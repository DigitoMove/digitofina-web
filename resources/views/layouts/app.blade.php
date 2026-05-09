<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'DigitoFina' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
    <div class="min-h-screen lg:flex">
        <aside class="border-r border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:w-64">
            <div class="flex h-16 items-center border-b border-slate-200 px-5">
                <div class="grid h-9 w-9 place-items-center rounded bg-blue-700 font-bold text-white shadow-sm">DF</div>
                <div class="ml-3">
                    <div class="font-semibold text-slate-950">DigitoFina</div>
                    <div class="text-xs text-slate-500">Financial Management Core</div>
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
                            ['Accounts', 'accounts.index', 'M3 7h18v12H3z M7 7V5h10v2'],
                            ['Loan Products', 'loan-products.index', 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                            ['Loans', 'loans.index', 'M4 6h16v12H4z M8 10h8 M8 14h5'],
                            ['Teller Drawer', 'teller.index', 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10'],
                            ['Reports', 'reports.index', 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h4'],
                        ];
                @endphp
                @foreach ($items as [$label, $route, $icon])
                    <a href="{{ route($route) }}" class="flex items-center gap-3 rounded px-3 py-2.5 font-medium transition {{ request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*') ? 'bg-blue-50 text-blue-800 ring-1 ring-blue-100' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950' }}">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
            <div class="mx-3 mt-2 rounded border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                <div class="font-semibold text-slate-800">Operational mode</div>
                <div class="mt-1">{{ auth()->user()->role?->name ?? 'User' }} · {{ now()->format('d M Y') }}</div>
            </div>
        </aside>

        <div class="flex min-h-screen flex-1 flex-col lg:pl-64">
            <header class="sticky top-0 z-20 border-b border-blue-950 bg-blue-800 text-white shadow-sm">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6">
                    @unless(auth()->user()->isMember())
                        <form action="{{ route('clients.index') }}" class="hidden flex-1 sm:block">
                            <input name="search" class="h-10 w-full max-w-xl rounded border-0 bg-white/95 px-4 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-2 focus:ring-white" placeholder="Search client, phone, account or national ID">
                        </form>
                    @else
                        <div class="hidden flex-1 text-sm font-medium text-blue-100 sm:block">Member self-service portal</div>
                    @endunless
                    <div class="flex items-center gap-3 text-sm">
                        <span class="hidden text-blue-100 md:inline">{{ auth()->user()->branch?->name }}</span>
                        <span class="rounded border border-white/15 bg-white/10 px-3 py-1">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded bg-blue-950 px-3 py-1.5 font-medium hover:bg-blue-900">Logout</button></form>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6">
                @if (session('status'))
                    <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
                @endif
                {{ $slot ?? '' }}
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
