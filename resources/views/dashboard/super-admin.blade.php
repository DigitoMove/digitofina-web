@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Super Admin Dashboard</h1>
        <p class="text-sm text-slate-500">
            @if($selectedOrganization)
                Working inside {{ $selectedOrganization->name }}. Use global view to see every organization again.
            @else
                System-wide organization, package, and portfolio overview.
            @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('organizations.create') }}" class="df-action-primary">New Organization</a>
        <a href="{{ route('organizations.index') }}" class="df-action-secondary">Manage Organizations</a>
    </div>
</div>

<section class="df-panel mb-6 p-5">
    <form method="POST" action="{{ $selectedOrganization ? route('organizations.switch.global') : '#' }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <label class="block min-w-72 text-sm font-medium text-slate-700">Organization context
            <select class="mt-1 w-full rounded border-slate-300" onchange="if (this.value) document.getElementById('switch-form-' + this.value).submit()">
                <option value="">Global Super Admin view</option>
                @foreach($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected($selectedOrganization?->id === $organization->id)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </label>
        @if($selectedOrganization)
            <button class="df-action-secondary">Return to Global View</button>
        @endif
    </form>
    @foreach($organizations as $organization)
        <form id="switch-form-{{ $organization->id }}" method="POST" action="{{ route('organizations.switch', $organization) }}" class="hidden">@csrf</form>
    @endforeach
</section>

@php
    $moduleEnabled = fn (string $slug): bool => in_array($slug, $enabledModules ?? [], true);
    $cards = [
        ['Organizations', $organizationCount, 'bg-brand-50 text-brand-800', ['platform']],
        ['Active organizations', $activeOrganizationCount, 'bg-emerald-50 text-emerald-700', ['platform']],
        ['Suspended/disabled', $suspendedOrganizationCount, 'bg-rose-50 text-rose-700', ['platform']],
        ['Staff users', $staffCount, 'bg-sky-50 text-sky-700', ['users']],
        ['Clients', $clientCount, 'bg-cyan-50 text-cyan-700', ['clients']],
        ['Loan portfolio', number_format($loanBalance, 0).' UGX', 'bg-amber-50 text-amber-700', ['loans']],
        ['Savings balance', number_format($savingsBalance, 0).' UGX', 'bg-teal-50 text-teal-700', ['savings']],
        ['Investment balance', number_format($investmentBalance, 0).' UGX', 'bg-indigo-50 text-indigo-700', ['investments']],
    ];
    $cards = array_values(array_filter($cards, function (array $card) use ($moduleEnabled, $selectedOrganization): bool {
        $modules = $card[3];

        if (in_array('platform', $modules, true)) {
            return ! $selectedOrganization;
        }

        return collect($modules)->contains(fn (string $module): bool => $moduleEnabled($module));
    }));
@endphp

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    @foreach($cards as [$label, $value, $class])
        <div class="df-panel p-5">
            <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $value }}</div>
            <div class="mt-4 h-1.5 rounded-full {{ $class }}"></div>
        </div>
    @endforeach
</div>

<section class="df-panel mt-6 p-5">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Recent Organizations</h2>
            <p class="text-sm text-slate-500">Newest tenants and their assigned packages.</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-3">Organization</th>
                    <th class="px-3 py-3">Package</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($recentOrganizations as $organization)
                    <tr>
                        <td class="px-3 py-3 font-medium text-slate-900">{{ $organization->name }}</td>
                        <td class="px-3 py-3 text-slate-600">{{ $organization->package?->name ?? 'No package' }}</td>
                        <td class="px-3 py-3"><span class="df-badge">{{ str($organization->status)->headline() }}</span></td>
                        <td class="px-3 py-3 text-right">
                            <a href="{{ route('organization.edit', $organization) }}" class="font-semibold text-brand-700">Edit</a>
                            <span class="mx-2 text-slate-300">/</span>
                            <a href="{{ route('organizations.users', $organization) }}" class="font-semibold text-brand-700">Users</a>
                            <span class="mx-2 text-slate-300">/</span>
                            <a href="{{ route('modules.index', ['organization_id' => $organization->id]) }}" class="font-semibold text-brand-700">Modules</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
