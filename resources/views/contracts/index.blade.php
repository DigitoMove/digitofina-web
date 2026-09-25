@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div><p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Contracts</p><h1 class="mt-1 text-2xl font-semibold text-slate-950">{{ $organization->name }} agreements</h1><p class="mt-2 text-sm text-slate-500">Create versioned agreements, share them for review, and keep the change history together.</p></div>
    <a href="{{ route('organizations.contracts.create', $organization) }}" class="df-action-primary">New contract</a>
</div>
<div class="df-panel overflow-hidden">
    <table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500"><tr><th class="px-4 py-3">Version</th><th class="px-4 py-3">Title</th><th class="px-4 py-3">Term</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Created</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y divide-slate-100">
    @forelse($contracts as $contract)
        <tr><td class="px-4 py-3 font-semibold">v{{ $contract->version }}</td><td class="px-4 py-3">{{ $contract->title }}</td><td class="px-4 py-3">{{ $contract->term_months }} months</td><td class="px-4 py-3"><span class="df-badge">{{ str($contract->status)->headline() }}</span></td><td class="px-4 py-3 text-slate-500">{{ $contract->created_at?->format('d M Y') }}</td><td class="px-4 py-3 text-right"><a class="font-semibold text-brand-700" href="{{ route('organizations.contracts.show', [$organization, $contract]) }}">Open</a></td></tr>
    @empty
        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No contracts have been created for this organization.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
