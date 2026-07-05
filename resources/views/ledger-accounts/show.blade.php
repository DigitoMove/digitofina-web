@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="{{ route('ledger-accounts.index', ['type' => $chartAccount->type]) }}" class="text-sm font-semibold text-brand-700 hover:underline">&larr; Ledger accounts</a>
        <h1 class="df-title mt-1">{{ $chartAccount->code }} · {{ $chartAccount->name }}</h1>
        <p class="df-muted">{{ $chartAccount->branch?->name ?? 'Organization-level shared ledger' }} · {{ str($chartAccount->type)->headline() }} · {{ str($chartAccount->normal_balance)->headline() }} normal balance</p>
    </div>
    <span class="rounded px-3 py-2 text-sm font-semibold {{ $chartAccount->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ $chartAccount->merged_into_id ? 'Merged source' : ($chartAccount->is_active ? 'Active' : 'Inactive') }}</span>
</div>

@if($errors->has('ledger_merge'))
    <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('ledger_merge') }}</div>
@endif

@if($chartAccount->mergedInto)
    <div class="mb-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        This ledger was merged into <a href="{{ route('ledger-accounts.show', $chartAccount->mergedInto) }}" class="font-semibold underline">{{ $chartAccount->mergedInto->code }} · {{ $chartAccount->mergedInto->name }}</a>. Its entries now appear on that destination ledger; this record remains for audit history.
    </div>
@endif

<section class="df-panel mb-5 p-5">
    <form method="GET" action="{{ route('ledger-accounts.show', $chartAccount) }}" class="grid gap-3 md:grid-cols-5">
        <label class="text-sm font-semibold text-slate-700">From
            <input type="date" name="from" value="{{ $statement['from']->toDateString() }}" class="mt-1">
        </label>
        <label class="text-sm font-semibold text-slate-700">To
            <input type="date" name="to" value="{{ $statement['to']->toDateString() }}" class="mt-1">
        </label>
        <label class="text-sm font-semibold text-slate-700">Branch
            <select name="branch_id" class="mt-1">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-700">Search
            <input name="search" value="{{ $search }}" class="mt-1" placeholder="Reference, client, account">
        </label>
        <div class="flex items-end gap-2">
            <button class="df-action-primary">Apply</button>
            <a href="{{ route('ledger-accounts.show', $chartAccount) }}" class="df-action-secondary">Reset</a>
        </div>
    </form>
</section>

<div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="df-soft-panel p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Opening balance</div>
        <div class="mt-1 text-xl font-bold {{ $statement['opening_balance'] < 0 ? 'text-red-700' : 'text-slate-950' }}">UGX {{ number_format($statement['opening_balance'], 2) }}</div>
        <div class="mt-1 text-xs text-slate-500">All posted activity before {{ $statement['from']->format('d M Y') }}</div>
        <div class="mt-2 text-xs text-slate-500">Debits {{ number_format($statement['opening_debits'], 2) }} · Credits {{ number_format($statement['opening_credits'], 2) }}</div>
    </div>
    <div class="df-soft-panel p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Client payments received</div>
        <div class="mt-1 text-xl font-bold text-emerald-700">UGX {{ number_format($statement['client_payments'], 2) }}</div>
        <div class="mt-1 text-xs text-slate-500">Period debits linked to client accounts and loan repayments</div>
    </div>
    <div class="df-soft-panel p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Transferred to drawers</div>
        <div class="mt-1 text-xl font-bold text-amber-700">UGX {{ number_format($statement['drawer_funding_out'], 2) }}</div>
        <div class="mt-1 text-xs text-slate-500">Period credits linked to drawer cash-in postings</div>
    </div>
    <div class="df-soft-panel p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Closing balance</div>
        <div class="mt-1 text-xl font-bold {{ $statement['closing_balance'] < 0 ? 'text-red-700' : 'text-slate-950' }}">UGX {{ number_format($statement['closing_balance'], 2) }}</div>
        <div class="mt-1 text-xs text-slate-500">Opening plus movement through {{ $statement['to']->format('d M Y') }}</div>
    </div>
</div>

@if($statement['closing_balance'] < 0)
    <div class="mb-5 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800">The ledger closes negative for this selection. Compare client payments of UGX {{ number_format($statement['client_payments'], 2) }} against drawer funding of UGX {{ number_format($statement['drawer_funding_out'], 2) }}, then review opening and other entries below.</div>
@endif

<div class="mb-5 grid gap-3 sm:grid-cols-3">
    <div class="df-soft-panel p-4"><div class="text-xs font-semibold uppercase text-slate-500">Period debits</div><div class="mt-1 text-lg font-bold">UGX {{ number_format($statement['period_debits'], 2) }}</div></div>
    <div class="df-soft-panel p-4"><div class="text-xs font-semibold uppercase text-slate-500">Period credits</div><div class="mt-1 text-lg font-bold">UGX {{ number_format($statement['period_credits'], 2) }}</div></div>
    <div class="df-soft-panel p-4"><div class="text-xs font-semibold uppercase text-slate-500">Deposited from drawers</div><div class="mt-1 text-lg font-bold">UGX {{ number_format($statement['drawer_deposits_in'], 2) }}</div></div>
</div>

<section class="df-panel overflow-hidden">
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 class="font-semibold text-slate-950">Ledger flows</h2>
        <p class="text-sm text-slate-500">Entries keep their original branch, date, reference, transaction, client, and drawer attributes after a merge.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Date / Reference</th><th>Branch</th><th>Source</th><th>Narration</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="pr-3 text-right">Running balance</th></tr></thead>
            <tbody>
                @forelse($statement['entries'] as $entry)
                    @php
                        $transaction = $entry->transaction;
                        $client = $transaction?->account?->client;
                        $statementDebit = (float) ($entry->statement_debit ?? ($entry->entry_type === 'debit' ? $entry->amount : 0));
                        $statementCredit = (float) ($entry->statement_credit ?? ($entry->entry_type === 'credit' ? $entry->amount : 0));
                        $source = $transaction?->account
                            ? (($client?->full_name ?: $client?->business_name ?: $client?->client_number) .' · '.$transaction->account->account_number)
                            : ($transaction?->drawerSession?->drawer ? 'Drawer · '.$transaction->drawerSession->drawer->code : 'Manual / opening journal');
                    @endphp
                    <tr class="border-t border-slate-200 align-top">
                        <td class="p-3 whitespace-nowrap"><div class="font-medium text-slate-950">{{ ($entry->journal_date ?? $entry->posted_at ?? $entry->created_at)->format('d M Y') }}</div><div class="text-xs text-slate-500">{{ $entry->reference ?: $entry->journal_number }}</div></td>
                        <td>{{ $entry->branch?->name ?? 'Organization-level' }}</td>
                        <td><div class="font-medium text-slate-950">{{ $source }}</div><div class="text-xs text-slate-500">{{ $transaction?->type?->name ?? ($entry->journal_number ?: 'Journal entry') }}</div></td>
                        <td class="max-w-80">{{ $entry->description ?: $entry->memo ?: '—' }}</td>
                        <td class="text-right font-medium">{{ $statementDebit > 0 ? number_format($statementDebit, 2) : '—' }}</td>
                        <td class="text-right font-medium">{{ $statementCredit > 0 ? number_format($statementCredit, 2) : '—' }}</td>
                        <td class="pr-3 text-right font-semibold {{ (float) $entry->running_balance_after < 0 ? 'text-red-700' : 'text-slate-950' }}">{{ number_format((float) $entry->running_balance_after, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-8 text-center text-slate-500">No posted ledger activity matches this period and filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
<div class="mt-4">{{ $statement['entries']->links() }}</div>

@if($chartAccount->mergedSources->isNotEmpty())
    <section class="df-panel mt-5 p-5">
        <h2 class="font-semibold text-slate-950">Merged source ledgers</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach($chartAccount->mergedSources as $source)
                <span class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm">{{ $source->code }} · {{ $source->branch?->name ?? 'Organization-level' }}</span>
            @endforeach
        </div>
    </section>
@endif

@if($canMergeLedgerAccounts && $chartAccount->branch_id === null && !$chartAccount->merged_into_id)
    <section class="df-panel mt-5 p-5">
        <h2 class="font-semibold text-slate-950">Merge duplicate ledgers into {{ $chartAccount->code }}</h2>
        <p class="mt-1 text-sm text-slate-500">Select only ledgers for the same provider. For example, merge branch MTN ledgers into global MTN; do not select Airtel ledgers.</p>
        @if($mergeCandidates->isNotEmpty())
            <form method="POST" action="{{ route('ledger-accounts.merge', $chartAccount) }}" class="mt-4 space-y-4" data-confirm-title="Merge ledger accounts" data-confirm-message="This permanently reclassifies accounting references to the destination and deactivates the source ledgers. Original branches and transaction attributes remain unchanged." data-confirm-label="Merge ledgers" data-confirm-tone="danger">
                @csrf
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach($mergeCandidates as $candidate)
                        <label class="flex items-start gap-3 rounded border border-slate-200 p-3 text-sm hover:bg-slate-50">
                            <input type="checkbox" name="source_account_ids[]" value="{{ $candidate->id }}" class="mt-1" @checked(in_array($candidate->id, old('source_account_ids', [])))>
                            <span><span class="block font-semibold text-slate-950">{{ $candidate->code }} · {{ $candidate->name }}</span><span class="text-slate-500">{{ $candidate->branch?->name ?? 'Organization-level' }}</span></span>
                        </label>
                    @endforeach
                </div>
                <label class="block text-sm font-semibold text-slate-700">Merge reason
                    <textarea name="reason" rows="2" class="mt-1" placeholder="Consolidating all branch MTN merchant-code ledgers into the shared MTN account">{{ old('reason') }}</textarea>
                </label>
                <button class="rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Merge selected ledgers</button>
            </form>
        @else
            <p class="mt-3 text-sm text-slate-500">No compatible unmerged ledgers are available.</p>
        @endif
    </section>
@endif
@endsection
