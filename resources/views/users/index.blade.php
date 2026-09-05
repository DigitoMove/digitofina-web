@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Staff User Management</h1>
        <p class="text-sm text-slate-500">Staff accounts, roles, branch assignment, and account status.</p>
    </div>
    <a href="{{ route('users.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New Staff User</a>
</div>

<form class="mb-4 grid gap-3 md:grid-cols-2 {{ auth()->user()->hasGlobalOrganizationAccess() ? 'xl:grid-cols-[1fr_14rem_14rem_14rem_12rem_auto]' : 'xl:grid-cols-[1fr_14rem_14rem_12rem_auto]' }}">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search name, email, phone or position">
    @if(auth()->user()->hasGlobalOrganizationAccess())
        <select name="organization_id" class="rounded border-slate-300" onchange="this.form.submit()">
            <option value="">All organizations</option>
            @foreach($organizations as $organization)
                <option value="{{ $organization->id }}" @selected(request('organization_id') == $organization->id)>{{ $organization->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="role_id" class="rounded border-slate-300" onchange="this.form.submit()"><option value="">All roles</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(request('role_id') == $role->id)>{{ $role->name }}</option>@endforeach</select>
    <select name="branch_id" class="rounded border-slate-300" onchange="this.form.submit()"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->organization?->name ? $branch->organization->name.' · ' : '' }}{{ $branch->name }}</option>@endforeach</select>
    <select name="status" class="rounded border-slate-300" onchange="this.form.submit()">
        <option value="">All statuses</option>
        @foreach(['active', 'suspended', 'disabled'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
        @endforeach
    </select>
    <div class="flex gap-2">
        <button class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
        @if(request()->filled('search') || request()->filled('organization_id') || request()->filled('role_id') || request()->filled('branch_id') || request()->filled('status'))
            <a href="{{ route('users.index') }}" class="rounded border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
        @endif
    </div>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Staff</th><th>Role</th><th>Organization</th><th>Branch</th><th>Status</th><th></th></tr></thead>
        <tbody>
            @forelse ($users as $staff)
                <tr class="border-t">
                    <td data-label="Staff" class="p-3 font-medium text-slate-950">{{ $staff->name }}<div class="text-slate-500">{{ $staff->email }} · {{ $staff->phone ?? 'No phone' }}</div></td>
                    <td data-label="Role">{{ $staff->role?->name ?? '-' }}</td>
                    <td data-label="Organization">{{ $staff->organization?->name ?? 'Platform' }}</td>
                    <td data-label="Branch">{{ $staff->branch?->name ?? 'All branches' }}</td>
                    <td data-label="Status"><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold">{{ str($staff->status)->headline() }}</span></td>
                    <td data-label="Action">
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('users.edit', $staff) }}" class="font-medium text-blue-700">Edit</a>
                            @if(auth()->user()->hasRole('super-admin') && ! $staff->hasRole('super-admin'))
                                <form method="POST" action="{{ route('users.impersonate', $staff) }}">
                                    @csrf
                                    <button class="font-medium text-amber-700">Act as user</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="p-4 text-slate-500" colspan="6">No staff users found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
