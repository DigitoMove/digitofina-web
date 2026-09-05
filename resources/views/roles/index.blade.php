@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Roles & Permissions</h1>
        <p class="text-sm text-slate-500">Control access by module and operational responsibility.</p>
    </div>
    <a href="{{ route('roles.create') }}" class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">New Role</a>
</div>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Role</th><th>Permissions</th><th>Users</th><th></th></tr></thead>
        <tbody>
            @foreach ($roles as $role)
                <tr class="border-t"><td class="p-3 font-medium">{{ $role->name }}<div class="text-slate-500">{{ $role->slug }}</div></td><td>{{ $role->permissions_count }}</td><td>{{ $role->users_count }}</td><td><x-icon-action :href="route('roles.edit', $role)" label="Edit role" icon="edit" /></td></tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $roles->links() }}</div>
@endsection
