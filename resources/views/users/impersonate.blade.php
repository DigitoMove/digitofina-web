@extends('layouts.app')

@section('content')
<div class="mb-5">
    <h1 class="df-title">Switch impersonated user</h1>
    <p class="df-muted">Choose an active staff account. Your current support session stays open until you select another user.</p>
</div>
<form method="GET" action="{{ route('impersonation.users') }}" class="mb-4 flex flex-wrap gap-2">
    <label class="sr-only" for="impersonation-search">Search staff or organization</label>
    <input id="impersonation-search" name="search" value="{{ $search }}" maxlength="191" placeholder="Name, email, or organization" class="min-w-0 flex-1 rounded border-slate-300">
    <button type="submit" class="df-action-secondary">Search</button>
    @if($search !== '')
        <a href="{{ route('impersonation.users') }}" class="df-action-secondary">Clear</a>
    @endif
</form>
<div class="df-panel overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Staff</th><th>Organization</th><th>Branch</th><th>Role</th><th>Action</th></tr></thead>
        <tbody>
            @forelse($users as $staff)
                <tr class="border-t border-slate-200">
                    <td class="p-3" data-label="Staff"><div class="font-semibold">{{ $staff->name }}</div><div class="text-slate-500">{{ $staff->email }}</div></td>
                    <td data-label="Organization">{{ $staff->organization->name }}</td>
                    <td data-label="Branch">{{ $staff->branch?->name ?? 'All branches' }}</td>
                    <td data-label="Role">{{ $staff->role->name }}</td>
                    <td class="p-3" data-label="Action">
                        <form method="POST" action="{{ route('users.impersonate', $staff) }}">
                            @csrf
                            <x-icon-action type="submit" label="Impersonate" icon="login" />
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-slate-500">No eligible staff users found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
