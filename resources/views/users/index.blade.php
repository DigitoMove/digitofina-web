@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">User Management</h1>
        <p class="text-sm text-slate-500">Staff accounts, roles, branch assignment, and account status.</p>
    </div>
    <a href="{{ route('users.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New User</a>
</div>

<form class="mb-4 grid gap-3 md:grid-cols-[1fr_14rem_14rem]">
    <input name="search" value="{{ request('search') }}" class="rounded border-slate-300" placeholder="Search name, email, phone or position">
    <select name="role_id" class="rounded border-slate-300"><option value="">All roles</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(request('role_id') == $role->id)>{{ $role->name }}</option>@endforeach</select>
    <select name="branch_id" class="rounded border-slate-300" onchange="this.form.submit()"><option value="">All branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
</form>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Staff</th><th>Role</th><th>Branch</th><th>Status</th><th></th></tr></thead>
        <tbody>
            @foreach ($users as $staff)
                <tr class="border-t">
                    <td class="p-3 font-medium text-slate-950">{{ $staff->name }}<div class="text-slate-500">{{ $staff->email }} · {{ $staff->phone ?? 'No phone' }}</div></td>
                    <td>{{ $staff->role?->name ?? '-' }}</td>
                    <td>{{ $staff->branch?->name ?? 'Global' }}</td>
                    <td><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold">{{ str($staff->status)->headline() }}</span></td>
                    <td><a href="{{ route('users.edit', $staff) }}" class="font-medium text-blue-700">Edit</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
