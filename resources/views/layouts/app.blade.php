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
@php
    $layoutUser = auth()->user();
    $layoutCanUseChat = $layoutUser && ! $layoutUser->isMember();
    $layoutChatUnreadCount = 0;
    $layoutChatLatestMessageId = 0;
    $layoutTenantContext = app(\App\Services\TenantContext::class);
    $layoutImpersonator = session()->has(\App\Http\Controllers\UserImpersonationController::IMPERSONATOR_SESSION_KEY)
        ? \App\Models\User::query()->find(session(\App\Http\Controllers\UserImpersonationController::IMPERSONATOR_SESSION_KEY))
        : null;
    $layoutOrganization = $layoutTenantContext->organization()
        ?: $layoutUser?->organization
        ?: $layoutUser?->branch?->organization;
    $layoutOrganizationLogo = $layoutOrganization?->logo;
    $layoutLogoUrl = filled($layoutOrganizationLogo)
        ? (\Illuminate\Support\Str::startsWith($layoutOrganizationLogo, ['http://', 'https://', '/'])
            ? $layoutOrganizationLogo
            : \Illuminate\Support\Facades\Storage::disk('public')->url($layoutOrganizationLogo))
        : asset('brand/digitofina-mark.svg');

    if ($layoutCanUseChat) {
        $layoutChatConversationScope = function ($query) use ($layoutUser): void {
            $query->where('participant_one_id', $layoutUser->id)
                ->orWhere('participant_two_id', $layoutUser->id);
        };
        $layoutChatUnreadCount = \App\Models\ChatMessage::query()
            ->where('sender_id', '!=', $layoutUser->id)
            ->whereNull('read_at')
            ->whereHas('conversation', $layoutChatConversationScope)
            ->count();
        $layoutChatLatestMessageId = (int) (\App\Models\ChatMessage::query()
            ->whereHas('conversation', $layoutChatConversationScope)
            ->max('id') ?? 0);
    }
@endphp
<body class="bg-slate-100 text-slate-900 antialiased" @if($layoutCanUseChat) data-chat-poll-url="{{ route('chat.poll') }}" data-chat-inbox-url="{{ route('chat.index') }}" data-chat-latest-id="{{ $layoutChatLatestMessageId }}" @endif>
    <div class="min-h-screen bg-slate-100 lg:flex">
        <div class="fixed inset-0 z-40 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden" data-sidebar-backdrop></div>

        <aside id="app-sidebar" class="fixed inset-y-0 left-0 z-50 flex w-80 max-w-[86vw] -translate-x-full flex-col border-r border-slate-200 bg-white shadow-2xl transition-transform duration-300 ease-out lg:fixed lg:inset-y-0 lg:w-72 lg:max-w-none lg:translate-x-0 lg:shadow-sm" data-sidebar>
            <div class="flex h-16 items-center justify-between border-b border-slate-200 px-5">
                <div class="flex min-w-0 items-center">
                    <img src="{{ $layoutLogoUrl }}" alt="{{ $layoutOrganization?->name ?? 'DigitoFina' }}" class="h-10 w-10 rounded-lg object-cover shadow-sm ring-1 ring-slate-200">
                    <div class="ml-3 min-w-0">
                        <div class="truncate font-semibold tracking-tight text-slate-950">{{ $layoutOrganization?->name ?? 'DigitoFina' }}</div>
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
                    $tenantContext = app(\App\Services\TenantContext::class);
                    $selectedOrganization = $tenantContext->organization();
                    $isGlobalSuperAdmin = $user->hasRole('super-admin') && ! $tenantContext->isScoped();
                    $moduleEnabled = fn (?string $slug): bool => $slug === null || $user->canAccessModule($slug);
                    $modulesEnabled = fn (array $slugs): bool => collect($slugs)->contains(fn (?string $slug): bool => $moduleEnabled($slug));
                    $canAny = fn (array $permissions): bool => $permissions === [] || collect($permissions)->contains(fn (string $permission): bool => $user->canPerform($permission));
                    $canViewOperationalReference = fn (): bool => $user->isTellerCashier() || $user->isLoanOfficer() || $user->isLoanRecoveryOfficer();
                    $isRouteActive = fn (string $route): bool => request()->routeIs($route) || request()->routeIs(strtok($route, '.').'.*');
                    $isItemActive = function (array $item) use ($isRouteActive): bool {
                        if (! $isRouteActive($item['route'])) {
                            return false;
                        }

                        if (($item['params'] ?? []) !== []) {
                            return collect($item['params'])->every(fn ($value, $key): bool => (request((string) $key) ?? request()->route((string) $key)) === $value);
                        }
                        return true;
                    };
                    $canManageOrganization = $user->canPerform('manage-organization') || $user->hasRole('super-admin') || $user->hasRole('supervisor') || $user->hasRole('organization-admin');
                    $organizationRoute = $user->hasRole('super-admin') ? 'organizations.index' : 'organization.current';
                    $navGroups = ($user->isMember() || $isGlobalSuperAdmin) ? [] : [
                        [
                            'label' => 'Clients',
                            'icon' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0',
                            'items' => [
                                ['label' => 'Clients', 'route' => 'clients.index', 'module' => 'clients', 'permissions' => [], 'icon' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0'],
                                ['label' => 'Client Groups', 'route' => 'client-groups.index', 'module' => 'clients', 'permissions' => ['manage-clients', 'view-reports'], 'icon' => 'M7 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M17 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M3 21a6 6 0 0 1 8-5.7 M13 15.3A6 6 0 0 1 21 21'],
                            ],
                        ],
                        [
                            'label' => 'Loans',
                            'icon' => 'M4 6h16v12H4z M8 10h8 M8 14h5',
                            'items' => [
                                ['label' => 'Loans', 'route' => 'loans.index', 'module' => 'loans', 'permissions' => [], 'icon' => 'M4 6h16v12H4z M8 10h8 M8 14h5'],
                                ['label' => 'Arrears', 'route' => 'arrears.index', 'module' => 'loans', 'permissions' => ['manage-loans', 'view-reports', 'view-arrears'], 'hide_for_cashier' => true, 'icon' => 'M12 8v5l3 3 M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0z'],
                                ['label' => 'Loan Products', 'route' => 'loan-products.index', 'module' => 'loans', 'permissions' => ['manage-loans'], 'operational_reference' => true, 'icon' => 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                            ],
                        ],
                        [
                            'label' => 'Operations',
                            'icon' => 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10',
                            'items' => [
                                ['label' => 'Teller Drawer', 'route' => 'teller.index', 'module' => 'teller', 'permissions' => ['post-transactions', 'post-repayments', 'manage-drawers'], 'icon' => 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10'],
                                ['label' => 'Reversal Requests', 'route' => 'transaction-reversal-requests.index', 'module' => 'teller', 'permissions' => ['approve-reversals', 'override-maker-checker'], 'icon' => 'M7 7h10M7 12h7m-7 5h5 M17 14l3 3-3 3 M20 17H9'],
                                ['label' => 'Transaction Search', 'route' => 'reports.show', 'params' => ['report' => 'all-posted-transactions'], 'module' => 'teller', 'permissions' => ['post-transactions', 'manage-drawers', 'view-reports'], 'icon' => 'M10 5a5 5 0 1 0 3.5 8.5L19 19 M8 10h4'],
                                ['label' => 'Drawer Ledger Postings', 'route' => 'expenses.index', 'module' => 'teller', 'permissions' => ['manage-drawers', 'manage-accounting'], 'icon' => 'M6 5h12v14H6z M9 9h6 M9 13h6 M9 17h3'],
                                ['label' => 'Non-Cash Transactions', 'route' => 'non-cash.index', 'module' => 'teller', 'permissions' => ['post-transactions', 'manage-accounting'], 'icon' => 'M7 7h10M7 12h10M7 17h6 M4 5h16v14H4z'],
                                ['label' => 'End of Day', 'route' => 'end-of-day.index', 'module' => 'teller', 'permissions' => ['manage-end-of-day', 'manage-drawers', 'view-reports'], 'icon' => 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5'],
                                ['label' => 'Data Imports', 'route' => 'imports.index', 'module' => 'settings', 'permissions' => ['import-data', 'manage-settings'], 'icon' => 'M12 3v12m0 0 4-4m-4 4-4-4 M5 19h14 M5 15v4 M19 15v4'],
                            ],
                        ],
                        [
                            'label' => 'Accounts',
                            'icon' => 'M3 7h18v12H3z M7 7V5h10v2',
                            'items' => [
                                ['label' => 'Accounts Register', 'route' => 'accounts.index', 'modules' => ['savings', 'loans', 'investments'], 'permissions' => ['manage-accounts', 'manage-loans', 'manage-investments', 'view-reports'], 'icon' => 'M3 7h18v12H3z M7 7V5h10v2'],
                                ['label' => 'Ledger Accounts', 'route' => 'ledger-accounts.index', 'module' => 'accounting', 'permissions' => ['manage-accounting'], 'icon' => 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5'],
                                ['label' => 'Savings Products', 'route' => 'savings-products.index', 'module' => 'savings', 'permissions' => ['manage-accounts'], 'icon' => 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'],
                                ['label' => 'Savings Transfer', 'route' => 'savings-transfers.create', 'module' => 'savings', 'permissions' => ['post-transactions'], 'icon' => 'M7 7h12m0 0-4-4m4 4-4 4 M17 17H5m0 0 4-4m-4 4 4 4'],
                            ],
                        ],
                    ];
                    $itemVisible = fn (array $item): bool => ! (($item['hide_for_cashier'] ?? false) && $user->isTellerCashier()) && $modulesEnabled($item['modules'] ?? [$item['module'] ?? null]) && ($canAny($item['permissions'] ?? []) || (($item['operational_reference'] ?? false) && $canViewOperationalReference()));
                    $navGroups = array_values(array_filter(array_map(function (array $group) use ($itemVisible, $user): array {
                        $group['items'] = array_values(array_filter($group['items'], $itemVisible));

                        if (($user->isLoanOfficer() || $user->isLoanRecoveryOfficer()) && in_array($group['label'], ['Operations', 'Accounts'], true)) {
                            $group['items'] = [];
                        }

                        if ($user->isTellerCashier()) {
                            $group['items'] = match ($group['label']) {
                                'Operations' => array_values(array_filter($group['items'], fn (array $item): bool => $item['label'] === 'Teller Drawer')),
                                'Accounts' => [],
                                default => $group['items'],
                            };
                        }

                        if ($user->isLoanRecoveryOfficer()) {
                            $group['items'] = match ($group['label']) {
                                'Clients' => array_values(array_filter($group['items'], fn (array $item): bool => $item['label'] === 'Clients')),
                                'Loans' => array_values(array_filter($group['items'], fn (array $item): bool => in_array($item['label'], ['Loans', 'Arrears', 'Loan Products'], true))),
                                default => $group['items'],
                            };
                        }

                        return $group;
                    }, $navGroups), fn (array $group): bool => $group['items'] !== []));
                    $adminItems = $user->isMember() ? [] : array_values(array_filter([
                        $canManageOrganization ? ['label' => $user->hasRole('super-admin') ? 'Organizations' : 'Organization', 'route' => $organizationRoute, 'icon' => 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5'] : null,
                        $user->hasAnyRole(['super-admin', 'organization-admin']) ? ['label' => 'Billing & Quotes', 'route' => 'commercial-quotes.index', 'icon' => 'M5 5h14v14H5z M8 9h8 M8 13h8 M8 17h5'] : null,
                        $user->hasAnyRole(['super-admin', 'organization-admin']) && ($user->organization_id || $layoutTenantContext->id()) ? ['label' => 'Nylon Pay Collections', 'route' => 'organization.nylon-pay.edit', 'icon' => 'M4 7h16v10H4z M7 12h4'] : null,
                        $user->canPerform('manage-branches') ? ['label' => 'Branches', 'route' => 'branches.index', 'icon' => 'M4 20V8l8-4 8 4v12 M9 20v-6h6v6'] : null,
                        $user->canPerform('manage-users') && $moduleEnabled('users') ? ['label' => 'Staff Users', 'route' => 'users.index', 'icon' => 'M16 11a4 4 0 1 0-8 0 M4 21a8 8 0 0 1 16 0'] : null,
                        $user->canPerform('manage-roles') && $moduleEnabled('roles') ? ['label' => 'Roles', 'route' => 'roles.index', 'icon' => 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-9V7z'] : null,
                        $user->hasRole('super-admin') ? ['label' => 'Modules', 'route' => 'modules.index', 'icon' => 'M4 6h16M4 12h16M4 18h16'] : null,
                        (! $user->hasRole('super-admin') && ($user->hasRole('organization-admin') || $user->canPerform('manage-organization'))) ? ['label' => 'Module Visibility', 'route' => 'modules.visibility', 'icon' => 'M5 12h14 M12 5v14'] : null,
                        $user->canPerform('manage-settings') && $moduleEnabled('settings') ? ['label' => 'Company Settings', 'route' => 'settings.index', 'icon' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z M4 12h2 M18 12h2 M12 4v2 M12 18v2'] : null,
                        ($user->hasRole('super-admin') || $user->hasRole('organization-admin')) ? ['label' => 'Branch Data Reset', 'route' => 'data-wipe.index', 'icon' => 'M5 6h14 M8 6V4h8v2 M7 10v9h10v-9 M10 13v3 M14 13v3'] : null,
                        $user->canPerform('view-audit-logs') && $moduleEnabled('audit-logs') ? ['label' => 'Audit Logs', 'route' => 'audit-logs.index', 'icon' => 'M5 4h14v16H5z M9 8h6 M9 12h6 M9 16h3'] : null,
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
                        @php($groupActive = collect($group['items'])->contains(fn ($item) => $isItemActive($item)))
                        <details class="group/nav rounded-lg" {{ $groupActive ? 'open' : '' }}>
                            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2.5 font-medium text-slate-600 transition hover:bg-slate-50 hover:text-brand-900 [&::-webkit-details-marker]:hidden {{ $groupActive ? 'bg-brand-50 text-brand-900 ring-1 ring-brand-200 shadow-sm' : '' }}">
                                <span class="grid h-8 w-8 place-items-center rounded-lg transition {{ $groupActive ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $group['icon'] }}"/></svg>
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>
                                <svg class="h-4 w-4 text-slate-400 transition group-open/nav:rotate-180" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </summary>
                            <div class="ml-4 mt-1 grid gap-1 border-l border-slate-200 pl-3">
                                @foreach($group['items'] as $item)
                                    @php($route = $item['route'])
                                    <a href="{{ route($route, $item['params'] ?? []) }}" class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $isItemActive($item) ? 'bg-brand-50 text-brand-900' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-900' }}">
                                        <span class="grid h-7 w-7 place-items-center rounded-lg transition {{ $isItemActive($item) ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $item['icon'] }}"/></svg>
                                        </span>
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach

                    @if(! $isGlobalSuperAdmin && $moduleEnabled('reports') && (! $user->isTellerCashier() || $moduleEnabled('accounting')) && $user->canPerform('view-reports') && ! ($user->isLoanOfficer() || $user->isLoanRecoveryOfficer()))
                    <a href="{{ route('reports.index') }}" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium transition {{ $isRouteActive('reports.index') ? 'bg-brand-50 text-brand-900 ring-1 ring-brand-200 shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-brand-900' }}">
                        <span class="grid h-8 w-8 place-items-center rounded-lg transition group-hover:scale-105 {{ $isRouteActive('reports.index') ? 'bg-brand-700 text-white' : 'bg-slate-100 text-brand-700' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h4"/></svg>
                        </span>
                        Reports
                    </a>
                    @endif

                    @if($adminItems !== [])
                        @php($adminActive = collect($adminItems)->contains(fn ($item) => $isRouteActive($item['route'])))
                        <details class="group/nav mt-2 rounded-lg border-t border-slate-200 pt-2" {{ $adminActive ? 'open' : '' }}>
                            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2.5 font-medium text-slate-600 transition hover:bg-sun-50 hover:text-sun-600 [&::-webkit-details-marker]:hidden {{ $adminActive ? 'bg-sun-50 text-sun-600 ring-1 ring-amber-100 shadow-sm' : '' }}">
                                <span class="grid h-8 w-8 place-items-center rounded-lg transition {{ $adminActive ? 'bg-sun-400 text-slate-950' : 'bg-sun-50 text-sun-600' }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z M4 12h2 M18 12h2 M12 4v2 M12 18v2"/></svg>
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ $isGlobalSuperAdmin ? 'Platform Administration' : 'Administration' }}</span>
                                <svg class="h-4 w-4 text-slate-400 transition group-open/nav:rotate-180" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </summary>
                            <div class="ml-4 mt-1 grid gap-1 border-l border-slate-200 pl-3">
                                @foreach ($adminItems as $item)
                                    @php($route = $item['route'])
                                    <a href="{{ route($route) }}" class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $isRouteActive($route) ? 'bg-sun-50 text-sun-600' : 'text-slate-600 hover:bg-sun-50 hover:text-sun-600' }}">
                                        <span class="grid h-7 w-7 place-items-center rounded-lg transition {{ $isRouteActive($route) ? 'bg-sun-400 text-slate-950' : 'bg-sun-50 text-sun-600' }}">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $item['icon'] }}"/></svg>
                                        </span>
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endif
            </nav>
            <div class="mx-3 mb-3 mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                <div class="font-semibold text-slate-800">Operational mode</div>
                <div class="mt-1">{{ auth()->user()->role?->name ?? 'User' }} · {{ $isGlobalSuperAdmin ? 'Global platform' : ($selectedOrganization?->name ?? auth()->user()->organization?->name ?? 'Company scope') }}</div>
                <div class="mt-1">{{ now()->format('d M Y') }}</div>
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
                    @if(! auth()->user()->isMember())
                        <form action="{{ route('clients.index') }}" class="hidden flex-1 sm:block">
                            <input name="search" class="h-11 w-full max-w-xl rounded-xl border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-4 focus:ring-brand-100" placeholder="Search clients by name, phone, account, or national ID">
                        </form>
                    @else
                        <div class="hidden flex-1 text-sm font-medium text-slate-500 sm:block">Member self-service portal</div>
                    @endif
                    <div class="flex items-center gap-3 text-sm">
                        @if($layoutCanUseChat)
                            <a href="{{ route('chat.index') }}" class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-slate-50 text-slate-700 shadow-sm transition hover:bg-brand-50 hover:text-brand-900" aria-label="Team chat">
                                <span class="relative">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                                        <path d="M4 5h16v10H7l-3 3V5z" />
                                    </svg>
                                    @if($layoutChatUnreadCount > 0)
                                        <span class="absolute -right-2 -top-2 grid min-h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[0.65rem] font-bold leading-none text-white" data-chat-unread-count>
                                            {{ $layoutChatUnreadCount > 99 ? '99+' : $layoutChatUnreadCount }}
                                        </span>
                                    @else
                                        <span class="hidden absolute -right-2 -top-2 min-h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[0.65rem] font-bold leading-none text-white" data-chat-unread-count></span>
                                    @endif
                                </span>
                            </a>
                        @endif
                        @include('notifications.dropdown')
                        <span class="hidden text-slate-500 md:inline">{{ auth()->user()->branch?->name }}</span>
                        <a href="{{ route('profile.edit') }}" class="max-w-32 truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 hover:bg-brand-50 sm:max-w-none">{{ auth()->user()->name }}</a>
                        <form method="POST" action="{{ route('logout') }}" class="sm:hidden">
                            @csrf
                            <button type="submit" class="grid h-10 w-10 place-items-center rounded-lg border border-slate-200 bg-slate-50 text-slate-700 transition hover:bg-red-50 hover:text-red-700" aria-label="Logout">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path d="M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4" />
                                    <path d="M15 7l5 5-5 5" />
                                    <path d="M20 12H9" />
                                </svg>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('logout') }}" class="hidden sm:block">@csrf<button class="rounded-lg bg-slate-900 px-3 py-1.5 font-semibold text-white hover:bg-brand-800">Logout</button></form>
                    </div>
                </div>
                @if(! auth()->user()->isMember())
                    <form action="{{ route('clients.index') }}" class="border-t border-slate-100 px-4 py-2 sm:hidden">
                        <input name="search" class="h-11 rounded-xl border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-4 focus:ring-brand-100" placeholder="Search clients, phone, ID">
                    </form>
                @endif
            </header>

            <main class="df-page flex-1 p-4 sm:p-6">
                @if($layoutImpersonator)
                    <div class="mb-4 flex flex-col justify-between gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-sm sm:flex-row sm:items-center">
                        <div>
                            <span class="font-semibold">Support session — Acting as {{ auth()->user()->name }}</span>
                            <span class="text-amber-800"> · {{ auth()->user()->role?->name }} at {{ $layoutOrganization?->name }}. Actions are live and audited.</span>
                        </div>
                        <form method="POST" action="{{ route('impersonation.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-lg bg-amber-900 px-3 py-2 font-semibold text-white hover:bg-amber-950">Return to {{ $layoutImpersonator->name }}</button>
                        </form>
                    </div>
                @endif
                @if ($layoutOrganization?->is_demo)
                    <div class="mb-4 flex flex-col justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-sm sm:flex-row sm:items-center">
                        <div>
                            <span class="font-bold">Demo organization</span>
                            <span class="ml-1">All modules are available with usage limits. {{ $layoutOrganization->demoDaysRemaining() }} day(s) remaining.</span>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-3">
                            <span class="font-semibold">Expires {{ $layoutOrganization->demo_expires_at?->format('d M Y') }}</span>
                            @if($layoutUser?->hasRole('super-admin') && $layoutTenantContext->isScoped())
                                <form method="POST" action="{{ route('organizations.switch.global') }}">
                                    @csrf
                                    <button class="rounded-lg bg-amber-900 px-3 py-2 font-semibold text-white transition hover:bg-amber-950">Clear active organization</button>
                                </form>
                            @elseif($layoutUser?->hasRole('organization-admin'))
                                <a href="{{ route('commercial-quotes.index') }}" class="rounded-lg bg-amber-900 px-3 py-2 font-semibold text-white transition hover:bg-amber-950">Request a quote</a>
                            @endif
                        </div>
                    </div>
                @endif
                <div class="fixed right-4 top-20 z-50 grid w-[min(24rem,calc(100vw-2rem))] gap-3">
                    @if (session('status'))
                        <div class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg" data-flash-toast>
                            <div class="min-w-0 flex-1">{{ session('status') }}</div>
                            <button type="button" class="-mr-1 grid h-6 w-6 shrink-0 place-items-center rounded-full text-emerald-700 transition hover:bg-emerald-100 focus:outline-none focus:ring-2 focus:ring-emerald-500" aria-label="Close notification" data-flash-dismiss>&times;</button>
                        </div>
                    @endif
                    @if (session('warning'))
                        <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 shadow-lg" data-flash-toast>
                            <div class="min-w-0 flex-1">{{ session('warning') }}</div>
                            <button type="button" class="-mr-1 grid h-6 w-6 shrink-0 place-items-center rounded-full text-amber-800 transition hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500" aria-label="Close notification" data-flash-dismiss>&times;</button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 shadow-lg" data-flash-toast>
                            <div class="min-w-0 flex-1">{{ $errors->first() }}</div>
                            <button type="button" class="-mr-1 grid h-6 w-6 shrink-0 place-items-center rounded-full text-red-700 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500" aria-label="Close notification" data-flash-dismiss>&times;</button>
                        </div>
                    @endif
                </div>
                {{ $slot ?? '' }}
                @yield('content')
            </main>
        </div>
    </div>
    @if(! auth()->user()->isMember())
        <nav class="df-mobile-nav lg:hidden" aria-label="Quick actions">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 4h16v6H4z M4 14h7v6H4z M15 14h5v6h-5z"/></svg>
                <span>Home</span>
            </a>
            <a href="{{ route('chat.index') }}" class="{{ request()->routeIs('chat.*') ? 'is-active' : '' }}">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 5h16v10H7l-3 3V5z"/></svg>
                <span>Chat</span>
            </a>
            @if(auth()->user()->canAccessModule('clients') && ! auth()->user()->isMember())
                <a href="{{ route('clients.index') }}" class="{{ request()->routeIs('clients.*') ? 'is-active' : '' }}">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0"/></svg>
                    <span>Clients</span>
                </a>
            @endif
            @if(auth()->user()->canAccessModule('teller') && (auth()->user()->canPerform('post-transactions') || auth()->user()->canPerform('post-repayments') || auth()->user()->canPerform('manage-drawers')))
                <a href="{{ route('teller.index') }}" class="{{ request()->routeIs('teller.*') ? 'is-active' : '' }}">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 7h16v10H4z M8 7V5h8v2 M7 12h10"/></svg>
                    <span>Teller</span>
                </a>
            @endif
            @if(auth()->user()->canAccessModule('loans') && ! auth()->user()->isMember())
                <a href="{{ route('loans.index') }}" class="{{ request()->routeIs('loans.*') || request()->routeIs('arrears.*') ? 'is-active' : '' }}">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 6h16v12H4z M8 10h8 M8 14h5"/></svg>
                    <span>Loans</span>
                </a>
            @endif
            <button type="button" data-sidebar-open aria-controls="app-sidebar" aria-expanded="false">
                <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                <span>Menu</span>
            </button>
        </nav>
    @endif
    @if($layoutCanUseChat)
        <div class="fixed bottom-24 right-4 z-[70] grid w-[min(24rem,calc(100vw-2rem))] gap-3 sm:bottom-4" data-chat-toast-region></div>
    @endif
    <div class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" data-confirm-dialog aria-hidden="true">
        <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-2xl ring-1 ring-slate-950/10" role="dialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message">
            <div class="flex items-start gap-3">
                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-700" data-confirm-icon>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M12 9v4m0 4h.01 M10.3 4.6 2.6 18a1.8 1.8 0 0 0 1.6 2.7h15.6a1.8 1.8 0 0 0 1.6-2.7L13.7 4.6a1.9 1.9 0 0 0-3.4 0z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 id="confirm-dialog-title" class="text-lg font-semibold text-slate-950" data-confirm-title>Confirm action</h2>
                    <p id="confirm-dialog-message" class="mt-2 text-sm leading-6 text-slate-600" data-confirm-message>Please confirm that you want to continue.</p>
                    <dl class="mt-4 hidden divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50 text-sm" data-confirm-summary></dl>
                </div>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-confirm-cancel>Cancel</button>
                <button type="button" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2" data-confirm-accept>Confirm</button>
            </div>
        </div>
    </div>
</body>
</html>
