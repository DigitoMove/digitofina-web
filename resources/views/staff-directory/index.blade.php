@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Staff Directory</h1>
        <p class="df-muted">Search colleagues by name, role, branch, phone, or email.</p>
    </div>
    <a href="{{ route('chat.index') }}" class="df-action-secondary">Open Chat</a>
</div>

<form class="df-panel mb-5 grid gap-3 p-4 md:grid-cols-[1fr_auto]">
    <label class="text-sm font-semibold text-slate-700">Search users
        <input name="search" value="{{ $search }}" class="mt-1" placeholder="Name, role, branch, phone, or email">
    </label>
    <button class="df-action-primary self-end">Search</button>
</form>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @forelse($users as $staff)
        <a href="{{ route('staff-directory.show', $staff) }}" class="df-panel df-hover-lift p-5">
            <div class="flex items-start gap-4">
                <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-lg font-semibold text-brand-800">
                    {{ str($staff->name)->substr(0, 1)->upper() }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-slate-950">{{ $staff->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $staff->role?->name ?? 'Unassigned role' }}</div>
                    <div class="mt-3 grid gap-1 text-xs text-slate-500">
                        <span>{{ $staff->branch?->name ?? 'All branches' }}</span>
                        <span>{{ $staff->organization?->name ?? 'Platform user' }}</span>
                        <span>{{ $staff->phone ?? $staff->email }}</span>
                    </div>
                </div>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-xs">
                <span class="df-badge">{{ str($staff->status)->headline() }}</span>
                <span class="font-semibold text-brand-700">View details</span>
            </div>
        </a>
    @empty
        <div class="df-panel p-8 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">
            No users matched your search.
        </div>
    @endforelse
</div>

<div class="mt-5">{{ $users->links() }}</div>
@endsection
