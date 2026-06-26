@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Branches</h1>
        <p class="text-sm text-slate-500">Manage institution locations and branch managers.</p>
    </div>
    <a href="{{ route('branches.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New Branch</a>
</div>

<form class="mb-4 grid gap-3 {{ auth()->user()->hasGlobalOrganizationAccess() ? 'md:grid-cols-[1fr_14rem_12rem_auto]' : 'md:grid-cols-[1fr_12rem_auto]' }}">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search branch code, name, phone or address">
    @if(auth()->user()->hasGlobalOrganizationAccess())
        <select name="organization_id" class="rounded border-slate-300" onchange="this.form.submit()">
            <option value="">All organizations</option>
            @foreach($organizations as $organization)
                <option value="{{ $organization->id }}" @selected(request('organization_id') == $organization->id)>{{ $organization->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="status" class="rounded border-slate-300" onchange="this.form.submit()">
        <option value="">All statuses</option>
        @foreach(['active', 'inactive', 'suspended'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
        @endforeach
    </select>
    <div class="flex gap-2">
        <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
        @if(request()->filled('search') || request()->filled('organization_id') || request()->filled('status'))
            <a href="{{ route('branches.index') }}" class="rounded border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
        @endif
    </div>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Branch</th><th>Organization</th><th>Manager</th><th>Staff</th><th>Phone</th><th>Status</th><th></th></tr></thead>
        <tbody>
            @forelse ($branches as $branch)
                <tr class="border-t">
                    <td data-label="Branch" class="p-3 font-medium text-slate-950">{{ $branch->code }}<div class="text-slate-500">{{ $branch->name }}</div></td>
                    <td data-label="Organization">{{ $branch->organization?->name ?? 'No organization' }}</td>
                    <td data-label="Manager">{{ $branch->manager?->name ?? 'Unassigned' }}</td>
                    <td data-label="Staff">{{ number_format($branch->users_count) }}</td>
                    <td data-label="Phone">{{ $branch->phone ?? '-' }}</td>
                    <td data-label="Status"><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold">{{ str($branch->status)->headline() }}</span></td>
                    <td data-label="Action"><a class="font-medium text-blue-700" href="{{ route('branches.edit', $branch) }}">Edit</a></td>
                </tr>
            @empty
                <tr><td class="p-4 text-slate-500" colspan="7">No branches found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $branches->links() }}</div>
@endsection
