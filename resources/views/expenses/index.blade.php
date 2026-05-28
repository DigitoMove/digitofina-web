@extends('layouts.app')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="text-sm font-semibold text-slate-500">Operations</div>
        <h1 class="df-title">{{ $type ? $types[$type] : 'Drawer Ledger Postings' }}</h1>
        <p class="df-muted">Review drawer-linked ledger postings and adjust cashier drawer cash when a posting is deleted.</p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-right shadow-sm">
        <div class="text-xs font-semibold uppercase text-slate-500">Listed total</div>
        <div class="text-lg font-semibold text-slate-950">UGX {{ number_format($totalAmount, 2) }}</div>
    </div>
</div>

<section class="df-panel overflow-hidden">
    <div class="grid gap-3 border-b border-slate-200 p-5 md:grid-cols-5">
        @foreach($types as $typeValue => $typeLabel)
            <a href="{{ route('expenses.index', array_filter(['type' => $typeValue, 'branch_id' => $branchId, 'search' => $search])) }}" class="rounded-lg border px-4 py-3 transition {{ $type === $typeValue ? 'border-brand-200 bg-brand-50 text-brand-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">
                <span class="block text-xs font-semibold uppercase text-slate-500">{{ $typeLabel }}</span>
                <span class="mt-1 block text-lg font-semibold">UGX {{ number_format($totals[$typeValue] ?? 0, 2) }}</span>
            </a>
        @endforeach
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 class="font-semibold text-slate-950">{{ $type ? $types[$type] : 'All Ledger Postings' }}</h2>
            <p class="text-sm text-slate-500">{{ $postings->total() }} posting{{ $postings->total() === 1 ? '' : 's' }} found.</p>
        </div>
        <form method="GET" action="{{ route('expenses.index') }}" class="flex flex-wrap items-center justify-end gap-2">
            @if($type)
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <select name="branch_id" class="rounded border-slate-300 text-sm">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
            <input name="search" value="{{ $search }}" class="min-w-72 rounded border-slate-300 text-sm" placeholder="Search description, reference, cashier, branch, or account">
            <button class="df-action-secondary">Filter</button>
            @if($search !== '' || $branchId || $type)
                <a href="{{ route('expenses.index', $type ? ['type' => $type] : []) }}" class="rounded border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
            @endif
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="p-3">Date</th>
                    <th>Branch</th>
                    <th>Cashier</th>
                    <th>Ledger Account</th>
                    <th>Drawer</th>
                    <th>Movement</th>
                    <th class="text-right">Amount</th>
                    <th class="pr-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($postings as $expense)
                    @php
                        $postingAccount = $expense->journalEntries->first(fn ($entry) => in_array($entry->chartAccount?->type, array_keys($types), true))?->chartAccount;
                        $drawerMovement = $expense->drawerMovements
                            ->where('drawer_session_id', $expense->drawer_session_id)
                            ->whereIn('type', ['cash_in', 'cash_out'])
                            ->first();
                    @endphp
                    <tr class="border-t border-slate-200 align-top">
                        <td class="p-3 whitespace-nowrap">
                            <div class="font-medium text-slate-950">{{ $expense->posted_at?->format('d M Y') }}</div>
                            <div class="text-xs text-slate-500">{{ $expense->posted_at?->format('H:i') }}</div>
                        </td>
                        <td>{{ $expense->drawerSession?->drawer?->branch?->name ?? 'Unknown branch' }}</td>
                        <td>
                            <div class="font-medium text-slate-950">{{ $expense->user?->name ?? 'Unknown cashier' }}</div>
                            <div class="text-xs text-slate-500">{{ $expense->user?->email }}</div>
                        </td>
                        <td>
                            <div class="font-medium text-slate-950">{{ $postingAccount ? $postingAccount->code.' · '.$postingAccount->name : 'Ledger account' }}</div>
                            <div class="text-xs text-slate-500">{{ $postingAccount?->type ? str($postingAccount->type)->headline() : 'Ledger' }}</div>
                            <div class="text-xs text-slate-500">{{ $expense->reference }}</div>
                            @if($expense->description)
                                <div class="mt-1 max-w-xl text-slate-600">{{ $expense->description }}</div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $expense->drawerSession?->drawer?->code }} {{ $expense->drawerSession?->drawer?->name }}</div>
                            <div class="text-xs text-slate-500">{{ str($expense->drawerSession?->drawer?->status ?? 'unknown')->headline() }} drawer</div>
                        </td>
                        <td>
                            <span class="rounded px-2 py-1 text-xs font-semibold {{ $drawerMovement?->type === 'cash_in' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $drawerMovement?->type === 'cash_in' ? 'Money came in' : 'Money went out' }}</span>
                        </td>
                        <td class="text-right font-semibold text-slate-950">UGX {{ number_format((float) $expense->amount, 2) }}</td>
                        <td class="pr-3 text-right">
                            <form method="POST" action="{{ route('expenses.destroy', $expense) }}" data-confirm-title="Delete ledger posting" data-confirm-message="Delete this ledger posting and apply the opposite cash movement to the cashier drawer?" data-confirm-label="Delete posting" data-confirm-tone="danger">
                                @csrf
                                @method('DELETE')
                                <button class="df-action-danger text-xs">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="p-8 text-center text-slate-500">No drawer ledger postings found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="mt-4">{{ $postings->links() }}</div>
@endsection
