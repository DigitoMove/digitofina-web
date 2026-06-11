@extends('layouts.app')

@section('content')
@php($displayName = $client->full_name ?: $client->business_name ?: $client->client_number)

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">{{ $displayName }}</h1>
        <p class="df-muted">{{ $client->client_number }} - {{ $client->branch?->name ?? 'No branch' }} - {{ str($client->status)->headline() }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if($client->officer && (int) $client->officer_id !== (int) auth()->id())
            <a href="{{ route('chat.index', ['recipient_id' => $client->officer_id, 'client_id' => $client->id]) }}" class="df-action-primary">Chat Officer</a>
        @endif
        <a href="{{ route('client-directory.index', request()->only('search')) }}" class="df-action-secondary">Back</a>
    </div>
</div>

<div class="grid gap-5 xl:grid-cols-[0.8fr_1.2fr]">
    <aside class="df-panel p-5">
        <div class="flex items-center gap-4">
            <div class="grid h-16 w-16 place-items-center rounded-xl bg-brand-50 text-2xl font-semibold text-brand-800">
                {{ str($displayName)->substr(0, 1)->upper() }}
            </div>
            <div class="min-w-0">
                <div class="truncate text-lg font-semibold text-slate-950">{{ $displayName }}</div>
                <div class="text-sm text-slate-500">{{ str($client->client_type)->headline() }} client</div>
            </div>
        </div>

        <dl class="mt-5 grid gap-3 text-sm">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Phone</dt><dd class="mt-1 text-slate-950">{{ $client->phone ?? 'No phone recorded' }}</dd></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Alternative Phone</dt><dd class="mt-1 text-slate-950">{{ $client->alternative_phone ?? 'None' }}</dd></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-xs font-semibold uppercase text-slate-500">National ID</dt><dd class="mt-1 text-slate-950">{{ $client->national_id ?? 'Not recorded' }}</dd></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-xs font-semibold uppercase text-slate-500">KYC Status</dt><dd class="mt-1 text-slate-950">{{ str($client->kyc_status)->headline() }} - {{ $client->kyc_completion_percentage }}%</dd></div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-xs font-semibold uppercase text-slate-500">Registered</dt><dd class="mt-1 text-slate-950">{{ $client->registered_at?->format('d M Y') ?? 'Not recorded' }}</dd></div>
        </dl>
    </aside>

    <div class="grid gap-5">
        <section class="df-panel p-5">
            <h2 class="text-lg font-semibold text-slate-950">Assigned Officer</h2>
            <div class="mt-4 rounded-lg border border-slate-200 p-4">
                @if($client->officer)
                    <div class="font-semibold text-slate-950">{{ $client->officer->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $client->officer->role?->name ?? 'Staff user' }} - {{ $client->officer->branch?->name ?? 'All branches' }}</div>
                    <div class="mt-2 text-sm text-slate-600">{{ $client->officer->phone ?? $client->officer->email }}</div>
                    @if((int) $client->officer_id !== (int) auth()->id())
                        <a href="{{ route('chat.index', ['recipient_id' => $client->officer_id, 'client_id' => $client->id]) }}" class="mt-4 inline-flex text-sm font-semibold text-brand-700 hover:text-brand-900">Start client conversation</a>
                    @endif
                @else
                    <div class="text-sm text-slate-500">No officer has been assigned to this client.</div>
                @endif
            </div>
        </section>

        <section class="df-panel p-5">
            <h2 class="text-lg font-semibold text-slate-950">Accounts</h2>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($client->accounts as $account)
                    <div class="grid gap-2 py-3 sm:grid-cols-[1fr_auto]">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $account->account_number }}</div>
                            <div class="text-xs text-slate-500">{{ $account->accountType?->name ?? 'Account' }} - {{ str($account->status)->headline() }}</div>
                        </div>
                        <div class="font-semibold text-slate-950">UGX {{ number_format((float) ($account->balance ?? $account->current_balance ?? 0), 0) }}</div>
                    </div>
                @empty
                    <div class="py-6 text-sm text-slate-500">No accounts found for this client.</div>
                @endforelse
            </div>
        </section>

        <section class="df-panel p-5">
            <h2 class="text-lg font-semibold text-slate-950">Loans</h2>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($client->loans as $loan)
                    <div class="grid gap-2 py-3 sm:grid-cols-[1fr_auto]">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $loan->loan_number }} - {{ $loan->product?->name ?? 'Loan' }}</div>
                            <div class="text-xs text-slate-500">{{ str($loan->status)->headline() }} - Officer: {{ $loan->officer?->name ?? 'Unassigned' }}</div>
                            @if($loan->officer && (int) $loan->officer_id !== (int) auth()->id())
                                <a href="{{ route('chat.index', ['recipient_id' => $loan->officer_id, 'client_id' => $client->id, 'loan_id' => $loan->id]) }}" class="mt-1 inline-flex text-xs font-semibold text-brand-700 hover:text-brand-900">Chat about this loan</a>
                            @endif
                        </div>
                        <div class="font-semibold text-slate-950">UGX {{ number_format((float) ($loan->approved_amount ?: $loan->requested_amount), 0) }}</div>
                    </div>
                @empty
                    <div class="py-6 text-sm text-slate-500">No loans found for this client.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
