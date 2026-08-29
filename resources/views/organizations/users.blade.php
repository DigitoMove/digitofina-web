@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">{{ $organization->name }} Users</h1>
        <p class="text-sm text-slate-500">Assign existing global users to this organization and review current staff.</p>
    </div>
    <a href="{{ route('users.create', ['organization_id' => $organization->id]) }}" class="df-action-primary">Create User</a>
</div>

<section class="df-panel mb-6 p-5">
    <form method="POST" action="{{ route('organizations.users.assign', $organization) }}" class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
        @csrf
        <label class="block text-sm font-medium text-slate-700">Unassigned user
            <select name="user_id" class="mt-1 w-full rounded border-slate-300" required>
                <option value="">Select user</option>
                @foreach($availableUsers as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} · {{ $user->email }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm font-medium text-slate-700">Branch
            <select name="branch_id" class="mt-1 w-full rounded border-slate-300">
                <option value="">All branches / no branch</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="self-end rounded bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800">Assign</button>
    </form>
</section>

<section class="df-panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Branch</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($users as $user)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-950">{{ $user->name }}</div>
                            <div class="text-xs text-slate-500">{{ $user->email }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->role?->name ?? 'No role' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->branch?->name ?? 'All branches' }}</td>
                        <td class="px-4 py-3"><span class="df-badge">{{ str($user->status)->headline() }}</span></td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('users.impersonate', $user) }}">
                                @csrf
                                <button class="font-semibold text-amber-700 hover:text-amber-900">Act as user</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No users assigned.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
