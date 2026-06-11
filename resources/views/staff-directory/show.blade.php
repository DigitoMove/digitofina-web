@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">{{ $staff->name }}</h1>
        <p class="df-muted">{{ $staff->role?->name ?? 'Unassigned role' }} · {{ $staff->branch?->name ?? 'All branches' }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('chat.index', ['recipient_id' => $staff->id]) }}" class="df-action-primary">Chat</a>
        <a href="{{ route('staff-directory.index', request()->only('search')) }}" class="df-action-secondary">Back</a>
    </div>
</div>

<div class="grid gap-5 xl:grid-cols-[0.8fr_1.2fr]">
    <aside class="df-panel p-5">
        <div class="flex items-center gap-4">
            <div class="grid h-16 w-16 place-items-center rounded-xl bg-brand-50 text-2xl font-semibold text-brand-800">
                {{ str($staff->name)->substr(0, 1)->upper() }}
            </div>
            <div>
                <div class="text-lg font-semibold text-slate-950">{{ $staff->name }}</div>
                <div class="text-sm text-slate-500">{{ $staff->position ?: $staff->role?->name }}</div>
            </div>
        </div>

        <dl class="mt-5 grid gap-3 text-sm">
            <div class="df-soft-panel p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Email</dt><dd class="mt-1 break-words text-slate-950">{{ $staff->email }}</dd></div>
            <div class="df-soft-panel p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Phone</dt><dd class="mt-1 text-slate-950">{{ $staff->phone ?? 'No phone recorded' }}</dd></div>
            <div class="df-soft-panel p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Organization</dt><dd class="mt-1 text-slate-950">{{ $staff->organization?->name ?? 'Platform user' }}</dd></div>
            <div class="df-soft-panel p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Branch</dt><dd class="mt-1 text-slate-950">{{ $staff->branch?->name ?? 'All branches' }}</dd></div>
            <div class="df-soft-panel p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Status</dt><dd class="mt-1 text-slate-950">{{ str($staff->status)->headline() }}</dd></div>
        </dl>
    </aside>

    <div class="grid gap-5">
        <section class="df-panel p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Assigned Clients</h2>
                    <p class="df-muted">Recent clients connected to this user.</p>
                </div>
            </div>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($assignedClients as $client)
                    <a href="{{ route('clients.show', $client) }}" class="flex items-start justify-between gap-3 py-3 hover:text-brand-800">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $client->full_name ?: $client->business_name }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $client->client_number }} · {{ $client->branch?->name ?? 'No branch' }}</div>
                        </div>
                        <span class="text-xs font-semibold text-brand-700">Open</span>
                    </a>
                @empty
                    <div class="py-8 text-sm text-slate-500">No assigned clients found.</div>
                @endforelse
            </div>
        </section>

        <section class="df-panel p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Assigned Loans</h2>
                    <p class="df-muted">Recent loans connected to this user.</p>
                </div>
            </div>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($assignedLoans as $loan)
                    <a href="{{ route('loans.show', $loan) }}" class="flex items-start justify-between gap-3 py-3 hover:text-brand-800">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $loan->loan_number }} · {{ $loan->client?->full_name }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $loan->product?->name }} · {{ str($loan->status)->headline() }}</div>
                        </div>
                        <span class="whitespace-nowrap text-xs font-semibold text-brand-700">UGX {{ number_format((float) ($loan->approved_amount ?: $loan->requested_amount), 0) }}</span>
                    </a>
                @empty
                    <div class="py-8 text-sm text-slate-500">No assigned loans found.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
