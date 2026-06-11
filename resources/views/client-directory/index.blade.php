@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Client Directory</h1>
        <p class="df-muted">Search clients by name, client number, phone, national ID, account, branch, or officer.</p>
    </div>
    <a href="{{ route('chat.index') }}" class="df-action-secondary">Open Chat</a>
</div>

<form class="df-panel mb-5 grid gap-3 p-4 md:grid-cols-[1fr_auto]">
    <label class="text-sm font-semibold text-slate-700">Search clients
        <input name="search" value="{{ $search }}" class="mt-1" placeholder="Name, phone, account, client number, or national ID">
    </label>
    <button class="df-action-primary self-end">Search</button>
</form>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @forelse($clients as $client)
        @php($displayName = $client->full_name ?: $client->business_name ?: $client->client_number)
        <a href="{{ route('client-directory.show', ['client' => $client->id, 'search' => $search]) }}" class="df-panel df-hover-lift p-5">
            <div class="flex items-start gap-4">
                <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-lg font-semibold text-brand-800">
                    {{ str($displayName)->substr(0, 1)->upper() }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-slate-950">{{ $displayName }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $client->client_number }} - {{ str($client->client_type)->headline() }}</div>
                    <div class="mt-3 grid gap-1 text-xs text-slate-500">
                        <span>{{ $client->phone ?? $client->alternative_phone ?? 'No phone recorded' }}</span>
                        <span>{{ $client->branch?->name ?? 'No branch' }}</span>
                        <span>Officer: {{ $client->officer?->name ?? 'Unassigned' }}</span>
                    </div>
                </div>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-xs">
                <span class="df-badge">{{ str($client->status)->headline() }}</span>
                <span class="font-semibold text-brand-700">{{ $client->accounts_count }} accounts - {{ $client->loans_count }} loans</span>
            </div>
        </a>
    @empty
        <div class="df-panel p-8 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">
            No clients matched your search.
        </div>
    @endforelse
</div>

<div class="mt-5">{{ $clients->links() }}</div>
@endsection
