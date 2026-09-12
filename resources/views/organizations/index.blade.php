@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Organizations</h1>
        <p class="text-sm text-slate-500">Create tenants, assign packages, switch context, and manage organization users.</p>
    </div>
    <a href="{{ route('organizations.create') }}" class="df-action-primary">Create Organization</a>
</div>

<section class="df-panel mb-5 p-4">
    <form method="GET" class="grid gap-3 md:grid-cols-[1fr_12rem_auto]">
        <input name="search" value="{{ request('search') }}" placeholder="Search organizations" class="rounded border-slate-300 text-sm">
        <select name="status" class="rounded border-slate-300 text-sm">
            <option value="">All statuses</option>
            @foreach(['active', 'suspended', 'disabled', 'inactive'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
            @endforeach
        </select>
        <button class="df-action-secondary">Filter</button>
    </form>
</section>

<section class="df-panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-[820px] divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Organization</th>
                    <th class="px-4 py-3">Package</th>
                    <th class="px-4 py-3">Branches</th>
                    <th class="px-4 py-3">Staff Users</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($organizations as $organization)
                    <tr class="transition hover:bg-slate-50/80">
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2.5">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sm font-bold text-brand-700">{{ str($organization->name)->substr(0, 1)->upper() }}</div>
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-slate-950">{{ $organization->name }}</div>
                                    <div class="truncate text-xs text-slate-500">{{ $organization->slug }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600"><span class="block max-w-44 truncate">{{ $organization->package?->name ?? 'No package' }}</span></td>
                        <td class="px-4 py-2.5 text-slate-600"><span class="font-semibold text-slate-900">{{ $organization->branches_count }}</span><span class="ml-1 text-xs text-slate-500">{{ str('branch')->plural($organization->branches_count) }}</span></td>
                        <td class="px-4 py-2.5 text-slate-600"><span class="font-semibold text-slate-900">{{ $organization->users_count }}</span><span class="ml-1 text-xs text-slate-500">staff</span></td>
                        <td class="px-4 py-2.5"><span class="df-badge {{ $organization->status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">{{ str($organization->status)->headline() }}</span></td>
                        <td class="px-4 py-2.5 text-right">
                            <details class="relative inline-block text-left">
                                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:border-brand-300 hover:bg-brand-50 hover:text-brand-900 [&::-webkit-details-marker]:hidden">
                                    Actions
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                                </summary>
                                <div class="absolute right-0 z-20 mt-2 grid w-52 gap-1 rounded-xl border border-slate-200 bg-white p-2 text-left shadow-xl">
                                <x-icon-action :href="route('organization.edit', $organization)" label="Edit organization" icon="edit" />
                                <x-icon-action :href="route('organizations.users', $organization)" label="Manage staff users" icon="settings" />
                                <x-icon-action :href="route('modules.index', ['organization_id' => $organization->id])" label="Manage modules" icon="settings" />
                                <x-icon-action :href="route('organizations.financial-defaults.index', $organization)" label="Financial defaults" icon="settings" />
                                <form method="POST" action="{{ route('organizations.switch', $organization) }}">@csrf<x-icon-action type="submit" text="Switch" label="Switch organization" icon="login" /></form>
                                <form method="POST" action="{{ route('organizations.destroy', $organization) }}" onsubmit="return confirm('Permanently delete this organization and all of its data? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="confirmation" value="DELETE">
                                    <x-icon-action type="submit" text="Delete permanently" label="Delete organization permanently" icon="delete" tone="danger" />
                                </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No organizations found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="mt-4">{{ $organizations->links() }}</div>
@endsection
