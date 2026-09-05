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
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
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
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-950">{{ $organization->name }}</div>
                            <div class="text-xs text-slate-500">{{ $organization->slug }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $organization->package?->name ?? 'No package' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $organization->branches_count }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $organization->users_count }}</td>
                        <td class="px-4 py-3"><span class="df-badge">{{ str($organization->status)->headline() }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex flex-wrap justify-end gap-3">
                                <a href="{{ route('organization.edit', $organization) }}" class="font-semibold text-brand-700">Edit</a>
                                <a href="{{ route('organizations.users', $organization) }}" class="font-semibold text-brand-700">Staff Users</a>
                                <a href="{{ route('modules.index', ['organization_id' => $organization->id]) }}" class="font-semibold text-brand-700">Modules</a>
                                <a href="{{ route('organizations.financial-defaults.index', $organization) }}" class="font-semibold text-brand-700">Defaults</a>
                                <form method="POST" action="{{ route('organizations.switch', $organization) }}">@csrf<button class="font-semibold text-brand-700">Switch</button></form>
                            </div>
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
