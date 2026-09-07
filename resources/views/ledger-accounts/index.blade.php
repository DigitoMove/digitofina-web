@extends('layouts.app')

@php
    $normalBalances = ['debit' => 'Debit', 'credit' => 'Credit'];
    $drawerDirections = ['cash_in' => 'Adds money to drawer', 'cash_out' => 'Takes money from drawer', 'both' => 'Can add or take drawer money', 'none' => 'Non-cash only (never use in drawer)'];
    $openCreateModal = old('_ledger_modal') === 'create';
    $selectedTypeLabel = $type ? $accountTypes[$type] : 'All Ledger Accounts';
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <div class="text-sm font-semibold text-slate-500">Accounts</div>
        <h1 class="df-title">{{ $selectedTypeLabel }}</h1>
        <p class="df-muted">Manage branch and organization-level chart-of-account records, balances, drawer postings, and reconciliations.</p>
    </div>
    <button type="button" class="df-action-primary" data-ledger-modal-open="ledger-create-modal">New {{ $type ? str($selectedTypeLabel)->singular() : 'Ledger Account' }}</button>
</div>

@if($errors->has('ledger_account'))
    <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('ledger_account') }}</div>
@endif
@if($errors->has('opening_balance'))
    <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('opening_balance') }}</div>
@endif

<section class="df-panel overflow-hidden">
    <div class="flex flex-wrap gap-2 border-b border-slate-200 px-5 py-4">
        <a href="{{ route('ledger-accounts.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold transition {{ $type ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-brand-700 text-white shadow-sm' }}">All</a>
        @foreach($accountTypes as $typeValue => $typeLabel)
            <a href="{{ route('ledger-accounts.index', ['type' => $typeValue]) }}" class="rounded-lg px-3 py-2 text-sm font-semibold transition {{ $type === $typeValue ? 'bg-brand-700 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $typeLabel }}</a>
        @endforeach
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 class="font-semibold text-slate-950">{{ $selectedTypeLabel }}</h2>
            <p class="text-sm text-slate-500">{{ $accounts->total() }} account{{ $accounts->total() === 1 ? '' : 's' }} found.</p>
        </div>
        <form method="GET" action="{{ route('ledger-accounts.index') }}" class="flex flex-wrap items-center justify-end gap-2">
            @if($type)
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <input name="search" value="{{ $search }}" class="min-w-72 rounded border-slate-300 text-sm" placeholder="Search code, name, branch, type, or parent">
            <button class="df-action-secondary">Search</button>
            @if($search !== '' || $type)
                <a href="{{ route('ledger-accounts.index', $type ? ['type' => $type] : []) }}" class="rounded border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
            @endif
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="p-3">Scope</th>
                    <th>Account</th>
                    <th>Classification</th>
                    <th>Drawer Impact</th>
                    <th class="text-right">Balance & Status</th>
                    <th class="text-right pr-3">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($accounts as $account)
                    @php
                        $debitTotal = (float) ($account->posted_line_debit_total ?? 0) + (float) ($account->posted_legacy_debit_total ?? 0);
                        $creditTotal = (float) ($account->posted_line_credit_total ?? 0) + (float) ($account->posted_legacy_credit_total ?? 0);
                        $currentBalance = $account->normal_balance === 'debit' ? $debitTotal - $creditTotal : $creditTotal - $debitTotal;
                    @endphp
                    <tr class="border-t border-slate-200 align-top">
                        <td class="p-3">{{ $account->branch?->name ?? 'Organization-level' }}</td>
                        <td>
                            <a href="{{ route('ledger-accounts.show', $account) }}" class="font-semibold text-brand-700 hover:underline">{{ $account->code }}</a>
                            <div class="font-medium text-slate-950">{{ $account->name }}</div>
                            <div class="text-xs text-slate-500">{{ $account->is_cash_account ? 'Cash ledger' : 'Operating ledger' }}{{ $account->is_system ? ' · System account' : '' }}</div>
                        </td>
                        <td><div>{{ str($account->type)->headline() }} · {{ str($account->normal_balance)->headline() }}</div><div class="text-xs text-slate-500">{{ $account->parent ? 'Parent: '.$account->parent->code.' · '.$account->parent->name : 'No parent' }}</div></td>
                        <td>
                            <div>{{ $account->drawer_direction === 'none' ? 'Non-cash only' : str($account->drawer_direction ?: 'both')->headline() }}</div>
                            <div class="text-xs text-slate-500">
                                @if($account->branch?->drawers?->isNotEmpty())
                                    {{ $account->branch->drawers->pluck('code')->join(', ') }}
                                @elseif($account->branch)
                                    No drawers configured
                                @else
                                    Not drawer-specific
                                @endif
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="font-semibold {{ $currentBalance < 0 ? 'text-red-700' : 'text-slate-950' }}">UGX {{ number_format($currentBalance, 2) }}</div>
                            <span class="rounded px-2 py-1 text-xs font-semibold {{ $account->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ $account->merged_into_id ? 'Merged' : ($account->is_active ? 'Active' : 'Inactive') }}</span>
                            @if($account->mergedInto)
                                <a href="{{ route('ledger-accounts.show', $account->mergedInto) }}" class="mt-1 block text-xs font-semibold text-brand-700">Into {{ $account->mergedInto->code }}</a>
                            @endif
                        </td>
                        <td class="pr-3 text-right">
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-icon-action :href="route('ledger-accounts.show', $account)" label="View ledger flows" />
                                @if(!$account->merged_into_id)
                                    <x-icon-action type="button" label="Edit ledger account" icon="edit" data-ledger-modal-open="ledger-edit-modal-{{ $account->id }}" />
                                @endif
                                @if($account->branch_id && !$account->merged_into_id)
                                    <x-icon-action type="button" label="Set opening balance" icon="settings" data-ledger-modal-open="ledger-opening-modal-{{ $account->id }}" />
                                @endif
                                @if($canDeleteLedgerAccounts)
                                    <form method="POST" action="{{ route('ledger-accounts.destroy', $account) }}" data-confirm-title="Delete ledger account" data-confirm-message="Delete this ledger account? Accounts with activity, children, cash status, or system status will be blocked." data-confirm-label="Delete account" data-confirm-tone="danger">
                                        @csrf
                                        @method('DELETE')
                                        <x-icon-action type="submit" text="Delete" label="Delete ledger account" icon="delete" tone="danger" />
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-8 text-center text-slate-500">No ledger accounts found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="mt-4">{{ $accounts->links() }}</div>

<div id="ledger-create-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4" data-ledger-modal data-open="{{ $openCreateModal ? '1' : '0' }}">
    <div class="w-full max-w-3xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">New Ledger Account</h2>
                <p class="text-sm text-slate-500">Create a branch ledger or a shared organization-level provider ledger.</p>
            </div>
            <button type="button" class="rounded px-3 py-1 text-2xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" data-ledger-modal-close aria-label="Close ledger account dialog">&times;</button>
        </div>
        <form method="POST" action="{{ route('ledger-accounts.store') }}" class="grid gap-3 p-5 md:grid-cols-2">
            @csrf
            <input type="hidden" name="_ledger_modal" value="create">
            <label class="text-sm font-semibold text-slate-700">Branch
                <select name="branch_id" class="mt-1">
                    @if($canCreateGlobalLedgerAccounts)
                        <option value="" @selected(blank(old('branch_id')))>Organization-level shared ledger</option>
                    @endif
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id') === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Code or Branch Suffix <x-required-badge />
                <input name="suffix" value="{{ old('suffix') }}" class="mt-1" placeholder="MTN-MERCHANT or 1105" required>
                <span class="mt-1 block text-xs text-slate-500">Organization-level uses this as the full code. Branch ledgers receive the branch prefix.</span>
            </label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">Account Name <x-required-badge />
                <input name="name" value="{{ old('name') }}" class="mt-1" placeholder="Bank Cash Source, Transport Expense, Donations Income" required>
            </label>
            <label class="text-sm font-semibold text-slate-700">Account Type <x-required-badge />
                <select name="type" class="mt-1" required>
                    @foreach($accountTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', $type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700">Normal Balance <x-required-badge />
                <select name="normal_balance" class="mt-1" required>
                    @foreach($normalBalances as $value => $label)
                        <option value="{{ $value }}" @selected(old('normal_balance') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">Drawer Use <x-required-badge />
                <select name="drawer_direction" class="mt-1" required>
                    @foreach($drawerDirections as $value => $label)
                        <option value="{{ $value }}" @selected(old('drawer_direction') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-slate-500">Organization-level ledgers can be used by every branch; each posting still records its originating branch.</span>
            </label>
            <div class="flex justify-end gap-2 md:col-span-2">
                <button type="button" class="df-action-secondary" data-ledger-modal-close>Cancel</button>
                <button class="df-action-primary">Create Ledger Account</button>
            </div>
        </form>
    </div>
</div>

@foreach($accounts as $account)
    <div id="ledger-edit-modal-{{ $account->id }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4" data-ledger-modal data-open="{{ old('_ledger_modal') === 'edit-'.$account->id ? '1' : '0' }}">
        <div class="max-h-[92vh] w-full max-w-4xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Edit {{ $account->code }}</h2>
                    <p class="text-sm text-slate-500">{{ $account->name }}</p>
                </div>
                <button type="button" class="rounded px-3 py-1 text-2xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" data-ledger-modal-close aria-label="Close edit ledger account dialog">&times;</button>
            </div>
            <form method="POST" action="{{ route('ledger-accounts.update', $account) }}" class="grid max-h-[78vh] gap-3 overflow-y-auto p-5 md:grid-cols-3">
                @csrf
                @method('PUT')
                <input type="hidden" name="_ledger_modal" value="edit-{{ $account->id }}">
                <input type="hidden" name="search" value="{{ $search }}">
                <label class="text-sm font-semibold text-slate-700">Branch
                    <select name="branch_id" class="mt-1">
                        <option value="">Organization-level ledger</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id', $account->branch_id) === $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Parent Account
                    <select name="parent_id" class="mt-1">
                        <option value="">No parent account</option>
                        @foreach($parentAccounts as $parentAccount)
                            @if($parentAccount->id !== $account->id)
                                <option value="{{ $parentAccount->id }}" @selected((int) old('parent_id', $account->parent_id) === $parentAccount->id)>{{ $parentAccount->code }} · {{ $parentAccount->name }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Code <x-required-badge />
                    <input name="code" value="{{ old('code', $account->code) }}" class="mt-1" required>
                </label>
                <label class="text-sm font-semibold text-slate-700 md:col-span-2">Account Name <x-required-badge />
                    <input name="name" value="{{ old('name', $account->name) }}" class="mt-1" required>
                </label>
                <label class="text-sm font-semibold text-slate-700">Account Type <x-required-badge />
                    <select name="type" class="mt-1" required>
                        @foreach($accountTypes as $value => $label)
                            <option value="{{ $value }}" @selected(old('type', $account->type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Normal Balance <x-required-badge />
                    <select name="normal_balance" class="mt-1" required>
                        @foreach($normalBalances as $value => $label)
                            <option value="{{ $value }}" @selected(old('normal_balance', $account->normal_balance) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Drawer Use
                    <select name="drawer_direction" class="mt-1">
                        <option value="">Can add or take drawer money</option>
                        @foreach($drawerDirections as $value => $label)
                            <option value="{{ $value }}" @selected(old('drawer_direction', $account->drawer_direction) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs text-slate-500">
                        @if($account->branch?->drawers?->isNotEmpty())
                            Affects {{ $account->branch->drawers->pluck('code')->join(', ') }} when posted through a drawer.
                        @else
                            This branch has no configured drawers yet.
                        @endif
                    </span>
                </label>
                <div class="flex flex-wrap items-center gap-4 text-sm font-semibold text-slate-700 md:col-span-2">
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $account->is_active))> Active
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="is_cash_account" value="0">
                        <input type="checkbox" name="is_cash_account" value="1" @checked((bool) old('is_cash_account', $account->is_cash_account))> Cash account
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="is_system" value="0">
                        <input type="checkbox" name="is_system" value="1" @checked((bool) old('is_system', $account->is_system))> System account
                    </label>
                </div>
                <div class="flex justify-end gap-2 md:col-span-3">
                    <button type="button" class="df-action-secondary" data-ledger-modal-close>Cancel</button>
                    <button class="df-action-primary">Update Ledger Account</button>
                </div>
            </form>
        </div>
    </div>

    @if($account->branch_id)
        <div id="ledger-opening-modal-{{ $account->id }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4" data-ledger-modal data-open="{{ old('_ledger_modal') === 'opening-'.$account->id ? '1' : '0' }}">
            <div class="w-full max-w-xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Post opening balance</h2>
                        <p class="text-sm text-slate-500">{{ $account->code }} · {{ $account->name }}</p>
                    </div>
                    <button type="button" class="rounded px-3 py-1 text-2xl leading-none text-slate-500 hover:bg-slate-100" data-ledger-modal-close aria-label="Close opening balance dialog">&times;</button>
                </div>
                <form method="POST" action="{{ route('ledger-accounts.opening-balance', $account) }}" class="grid gap-4 p-5 sm:grid-cols-2" data-confirm-title="Post ledger opening balance" data-confirm-message="This creates a permanent balanced journal entry. Use another adjustment entry if it needs correction." data-confirm-label="Post balance">
                    @csrf
                    <input type="hidden" name="_ledger_modal" value="opening-{{ $account->id }}">
                    <label class="text-sm font-semibold text-slate-700">Amount <x-required-badge />
                        <input name="amount" type="number" min="100" step="100" value="{{ old('_ledger_modal') === 'opening-'.$account->id ? old('amount') : '' }}" class="mt-1" required>
                    </label>
                    <label class="text-sm font-semibold text-slate-700">Balance side <x-required-badge />
                        <select name="entry_type" class="mt-1" required>
                            @foreach($normalBalances as $value => $label)
                                <option value="{{ $value }}" @selected((old('_ledger_modal') === 'opening-'.$account->id ? old('entry_type', $account->normal_balance) : $account->normal_balance) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-semibold text-slate-700 sm:col-span-2">Opening date <x-required-badge />
                        <input name="opening_date" type="date" max="{{ today()->toDateString() }}" value="{{ old('_ledger_modal') === 'opening-'.$account->id ? old('opening_date', today()->toDateString()) : today()->toDateString() }}" class="mt-1" required>
                    </label>
                    <label class="text-sm font-semibold text-slate-700 sm:col-span-2">Narration
                        <textarea name="description" rows="3" class="mt-1" placeholder="Imported Merchant Code balance, opening bank position...">{{ old('_ledger_modal') === 'opening-'.$account->id ? old('description') : '' }}</textarea>
                    </label>
                    <p class="rounded border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 sm:col-span-2">The opposite side is posted automatically to the branch Opening Balance Equity ledger, keeping the journal balanced.</p>
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <button type="button" class="df-action-secondary" data-ledger-modal-close>Cancel</button>
                        <button class="df-action-primary">Post Opening Balance</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endforeach
@endsection
