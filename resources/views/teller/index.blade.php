@extends('layouts.app')

@section('content')
@php
    $statusClass = $session ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200';
    $tiles = [
        ['Drawer Balance', number_format($drawer?->current_balance ?? 0, 2).' UGX', 'bg-brand-600', 'M4 7h16v10H4z M8 7V5h8v2 M7 12h10'],
        ['Cash In Today', number_format($cashIn, 2).' UGX', 'bg-emerald-600', 'M12 5v14m0-14 5 5m-5-5-5 5'],
        ['Cash Out Today', number_format($cashOut, 2).' UGX', 'bg-amber-500', 'M12 19V5m0 14 5-5m-5 5-5-5'],
    ];
    $isLedgerPosting = filled($selectedChartAccount);
    $selectedLedgerEntryType = $isLedgerPosting && in_array($selectedChartAccount->drawer_direction, ['cash_in', 'cash_out'], true)
        ? $selectedChartAccount->drawerEntryType($selectedChartAccount->drawer_direction)
        : null;
    $needsLedgerDirection = $isLedgerPosting && ! $selectedLedgerEntryType;
    $isLoanAccountPosting = $account?->accountType?->category === 'loan';
    $selectedLoan = $account?->loan;
    $selectedLoanRepayable = $selectedLoan
        ? (float) $selectedLoan->principal_balance + (float) $selectedLoan->interest_balance + (float) $selectedLoan->fees_balance + (float) $selectedLoan->penalty_balance
        : null;
    $availableTransactionTypes = $isLoanAccountPosting
        ? $transactionTypes->where('code', 'loan_repayment')
        : $transactionTypes->reject(fn ($type) => $type->code === 'loan_repayment');
@endphp

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Teller / Cashier Dashboard</h1>
        <p class="df-muted">Drawer operations, account lookup, postings, and today’s collections.</p>
    </div>
    <div class="rounded border px-4 py-3 text-sm {{ $statusClass }}">
        <span class="font-semibold">{{ $session ? 'Open drawer' : 'No open drawer' }}</span>
        <span class="ml-2">{{ $drawer?->name ?? ($isCashier ? 'Open your assigned drawer to start' : 'Open a drawer to start') }}</span>
    </div>
</div>

<div class="grid gap-4 sm:grid-cols-3">
    @foreach($tiles as [$label, $value, $color, $icon])
        <div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
            <div class="{{ $color }} relative p-5 text-white">
                <div class="text-3xl font-semibold">{{ $value }}</div>
                <div class="mt-2 text-sm font-semibold">{{ $label }}</div>
                <svg class="absolute right-5 top-5 h-16 w-16 text-white/20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
            </div>
            <div class="bg-slate-900/10 px-5 py-2 text-right text-xs font-semibold text-slate-600">Today</div>
        </div>
    @endforeach
</div>

<div class="mt-6 grid items-start gap-5 xl:grid-cols-3">
    @if($isCashier || ! $canOpenForTellers || $session)
    <section class="df-panel p-5 xl:col-span-1">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Drawer Opening</h2>
                <p class="df-muted">{{ $isCashier ? 'Open the drawer assigned to you.' : 'Open a till, or continue with the active drawer.' }}</p>
            </div>
            <span class="df-badge {{ $session ? 'border-emerald-500/20 bg-emerald-50 text-emerald-700' : 'border-amber-400/20 bg-amber-50 text-amber-700' }}">{{ $session ? 'Ready' : 'Required' }}</span>
        </div>
        @unless($session)
            @if($branchesWithoutDrawers->isNotEmpty())
                <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    No drawer is configured for {{ $branchesWithoutDrawers->pluck('name')->join(', ') }}. Create a drawer in Drawer Management before tellers can open one for that branch.
                </div>
            @endif
            <form method="POST" action="{{ route('teller.open') }}" class="mt-5 grid gap-3">
                @csrf
                <label class="text-sm font-semibold text-slate-700">Drawer <x-required-badge />
                    <select name="teller_drawer_id" class="mt-1" required>
                        @forelse($drawers->where('status', 'closed') as $openDrawer)
                            <option value="{{ $openDrawer->id }}" @selected((int) old('teller_drawer_id') === $openDrawer->id)>{{ $openDrawer->branch?->name }} · {{ $openDrawer->code }} {{ $openDrawer->name }} · {{ str($openDrawer->status)->headline() }}</option>
                        @empty
                            <option value="">No closed drawers available for your branch</option>
                        @endforelse
                    </select>
                </label>
                @if($canOpenForTellers)
                    <label class="text-sm font-semibold text-slate-700">Teller / Cashier
                        <select name="teller_user_id" class="mt-1">
                            <option value="">Select teller/cashier</option>
                            @foreach($tellers as $teller)
                                <option value="{{ $teller->id }}" @selected((int) old('teller_user_id') === $teller->id)>{{ $teller->name }} · {{ $teller->role?->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if($isCashier)
                    <input type="hidden" name="opening_balance" value="{{ old('opening_balance', $drawers->where('status', 'closed')->first()?->current_balance ?? 0) }}">
                @else
                    <label class="text-sm font-semibold text-slate-700">Opening Balance <x-required-badge />
                        <input name="opening_balance" type="number" min="0" step="100" value="{{ old('opening_balance') }}" class="mt-1" placeholder="Opening balance" required>
                    </label>
                @endif
                <button class="df-action-primary">Open Drawer</button>
            </form>
        @else
            <div class="mt-5 grid gap-3 text-sm">
                <div class="df-soft-panel flex items-center justify-between gap-3 p-4">
                    <span>Opening balance</span>
                    <span class="font-semibold text-slate-950">UGX {{ number_format($session->opening_balance, 2) }}</span>
                </div>
                <div class="df-soft-panel flex items-center justify-between gap-3 p-4">
                    <span>Expected balance</span>
                    <span class="font-semibold text-slate-950">UGX {{ number_format($expectedBalance, 2) }}</span>
                </div>
                <div class="df-soft-panel flex items-center justify-between gap-3 p-4">
                    <span>Net movement</span>
                    <span class="font-semibold {{ $netCashMovement >= 0 ? 'text-emerald-700' : 'text-red-700' }}">UGX {{ number_format($netCashMovement, 2) }}</span>
                </div>
            </div>
        @endunless
    </section>
    @endif

    @if($canOpenForTellers)
        <section class="df-panel p-5 xl:col-span-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-slate-950">Drawer Management</h2>
                    <p class="df-muted">Create branch drawers, edit drawer details, assign tellers/cashiers, open, close, or delete unused drawers.</p>
                </div>
                <span class="df-badge">Branch scoped</span>
            </div>

            @if($errors->has('drawer_management'))
                <div class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('drawer_management') }}</div>
            @endif

            <form method="POST" action="{{ route('teller.drawers.store') }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-2">
                @csrf
                <label class="text-sm font-semibold text-slate-700">Branch <x-required-badge />
                    <select name="branch_id" class="mt-1" required>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id') === $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Assigned teller/cashier
                    <select name="assigned_user_id" class="mt-1">
                        <option value="">Unassigned</option>
                        @foreach($tellers as $teller)
                            <option value="{{ $teller->id }}" @selected((int) old('assigned_user_id') === $teller->id)>{{ $teller->name }} · {{ $teller->branch?->name ?? 'No branch' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-700">Drawer name <x-required-badge />
                    <input name="name" value="{{ old('name') }}" class="mt-1" placeholder="Main Drawer, Teller 2 Drawer" required>
                </label>
                <label class="text-sm font-semibold text-slate-700">Drawer code <x-required-badge />
                    <input name="code" value="{{ old('code') }}" class="mt-1" placeholder="BR01-TD-02" required>
                </label>
                <button class="df-action-primary md:col-span-2">Create Drawer</button>
            </form>

            <div class="mt-5 max-h-[28rem] overflow-auto rounded-lg border border-slate-200">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-800 text-white">
                        <tr><th class="p-3">Branch</th><th>Drawer</th><th>Assigned</th><th class="text-right">Balance</th><th>Status</th><th class="text-right">Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($drawers->groupBy('branch_id') as $branchDrawers)
                            @foreach($branchDrawers as $managedDrawer)
                                <tr class="border-t border-slate-200 align-top">
                                    <td class="p-3">{{ $managedDrawer->branch?->name ?? 'No branch' }}</td>
                                    <td>
                                        <div class="font-semibold text-slate-950">{{ $managedDrawer->code }}</div>
                                        <div class="text-slate-500">{{ $managedDrawer->name }}</div>
                                    </td>
                                    <td>{{ $managedDrawer->assignedUser?->name ?? 'Unassigned' }}</td>
                                    <td class="text-right">UGX {{ number_format((float) $managedDrawer->current_balance, 2) }}</td>
                                    <td><span class="rounded px-2 py-1 text-xs font-semibold {{ $managedDrawer->status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ str($managedDrawer->status)->headline() }}</span></td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if($managedDrawer->status === 'open')
                                                <form method="POST" action="{{ route('teller.drawers.close', $managedDrawer) }}" class="flex gap-2">
                                                    @csrf
                                                    <input name="closing_balance" type="number" min="0" step="100" value="{{ $managedDrawer->current_balance }}" class="w-28 rounded border-slate-300 text-sm" required>
                                                    <button class="rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white">Close</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('teller.open') }}" class="flex flex-wrap justify-end gap-2">
                                                    @csrf
                                                    <input type="hidden" name="teller_drawer_id" value="{{ $managedDrawer->id }}">
                                                    <select name="teller_user_id" class="w-40 rounded border-slate-300 text-sm" required>
                                                        <option value="">Teller</option>
                                                        @foreach($tellers as $teller)
                                                            <option value="{{ $teller->id }}" @selected($managedDrawer->assigned_user_id === $teller->id)>{{ $teller->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input name="opening_balance" type="number" min="0" step="100" value="{{ $managedDrawer->current_balance }}" class="w-28 rounded border-slate-300 text-sm" required>
                                                    <button class="rounded bg-brand-700 px-3 py-1 text-xs font-semibold text-white">Open</button>
                                                </form>
                                                <form method="POST" action="{{ route('teller.drawers.destroy', $managedDrawer) }}" data-confirm-title="Delete drawer" data-confirm-message="Delete this drawer? Only unused closed drawers can be deleted." data-confirm-label="Delete drawer" data-confirm-tone="danger">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded border border-red-200 px-3 py-1 text-xs font-semibold text-red-700">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                <tr class="border-t border-slate-100 bg-slate-50/60">
                                    <td colspan="6" class="p-3">
                                        <details class="rounded-lg border border-slate-200 bg-white p-3">
                                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-xs font-semibold text-brand-700">
                                                <span>Edit drawer details for {{ $managedDrawer->code }} · {{ $managedDrawer->name }}</span>
                                                <span class="text-slate-400">Expand</span>
                                            </summary>
                                            <form method="POST" action="{{ route('teller.drawers.update', $managedDrawer) }}" class="mt-4 grid gap-3 md:grid-cols-3">
                                                @csrf
                                                @method('PUT')
                                                <label class="text-xs font-semibold text-slate-600">Branch
                                                    <select name="branch_id" class="mt-1 w-full rounded border-slate-300 text-sm" required>
                                                        @foreach($branches as $branch)
                                                            <option value="{{ $branch->id }}" @selected((int) old('branch_id', $managedDrawer->branch_id) === $branch->id)>{{ $branch->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="text-xs font-semibold text-slate-600">Assigned teller/cashier
                                                    <select name="assigned_user_id" class="mt-1 w-full rounded border-slate-300 text-sm">
                                                        <option value="">Unassigned</option>
                                                        @foreach($tellers as $teller)
                                                            <option value="{{ $teller->id }}" @selected((int) old('assigned_user_id', $managedDrawer->assigned_user_id) === $teller->id)>{{ $teller->name }} · {{ $teller->branch?->name ?? 'No branch' }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="text-xs font-semibold text-slate-600">Drawer name
                                                    <input name="name" value="{{ old('name', $managedDrawer->name) }}" class="mt-1 w-full rounded border-slate-300 text-sm" required>
                                                </label>
                                                <label class="text-xs font-semibold text-slate-600">Drawer code
                                                    <input name="code" value="{{ old('code', $managedDrawer->code) }}" class="mt-1 w-full rounded border-slate-300 text-sm" required>
                                                </label>
                                                <label class="text-xs font-semibold text-slate-600">Currency
                                                    <input name="currency" value="{{ old('currency', $managedDrawer->currency) }}" class="mt-1 w-full rounded border-slate-300 text-sm" maxlength="10" required>
                                                </label>
                                                <div class="flex items-end justify-end">
                                                    <button class="rounded bg-slate-900 px-4 py-2 text-xs font-semibold text-white">Save Details</button>
                                                </div>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td colspan="6" class="p-8 text-center text-slate-500">No drawers configured yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="df-panel p-5 {{ $canOpenForTellers ? 'xl:col-span-3' : 'xl:col-span-2' }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Account Lookup</h2>
                <p class="df-muted">Find savings, loan, or GL posting targets.</p>
            </div>
            @unless($isCashier)
                <a href="{{ route('due-repayments.index') }}" class="df-action-secondary">Due Repayments</a>
            @endunless
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-[260px_1fr_auto]">
            <select name="lookup_scope" data-teller-lookup-scope required>
                <option value="">Select account type</option>
                @foreach($accountTypes as $type)
                    <option value="account_type:{{ $type->id }}" @selected($lookupScope === 'account_type:'.$type->id || ($selectedAccountTypeId === $type->id && ! $isGeneralLedgerLookup))>{{ $type->name }}</option>
                @endforeach
                <option value="general_ledger" @selected($isGeneralLedgerLookup)>General Ledger Account</option>
            </select>
            <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                @if($selectedChartAccount)
                    <div class="font-semibold text-slate-950">{{ $selectedChartAccount->code }} · {{ $selectedChartAccount->name }}</div>
                    <div class="text-slate-500">General Ledger Account</div>
                @elseif($account)
                    <div class="font-semibold text-slate-950">{{ $account->account_number }} · {{ $account->account_name }}</div>
                    <div class="text-slate-500">{{ $account->accountType?->name }} · {{ $account->client?->full_name ?: $account->client?->business_name }}</div>
                @else
                    <div class="font-semibold text-slate-500">No account selected</div>
                    <div class="text-slate-500">Choose an account type, then search.</div>
                @endif
            </div>
            <button type="button" class="df-action-primary justify-center" data-teller-lookup-open>
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="m21 21-4.3-4.3M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"/></svg>
                Search
            </button>
        </div>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div class="rounded border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Selected client account</div>
                <div class="mt-1 font-semibold text-slate-950">{{ $account?->account_name ?? 'Select a client account above' }}</div>
                @if($account)
                    <div class="text-sm text-slate-500">{{ $account->account_number }} · {{ $account->accountType?->name }} · {{ $account->client?->full_name ?: $account->client?->business_name }}</div>
                    <div class="mt-2 text-xl font-semibold text-brand-800">UGX {{ number_format((float) $account->balance, 2) }}</div>
                    @if($selectedLoanRepayable !== null)
                        <div class="mt-3 rounded border border-brand-200 bg-white p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-semibold text-slate-700">Total to repay</span>
                                <span class="font-semibold text-brand-800">UGX {{ number_format($selectedLoanRepayable, 2) }}</span>
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Principal UGX {{ number_format((float) $selectedLoan->principal_balance, 2) }}
                                · Interest UGX {{ number_format((float) $selectedLoan->interest_balance, 2) }}
                                @if((float) $selectedLoan->fees_balance > 0)
                                    · Fees UGX {{ number_format((float) $selectedLoan->fees_balance, 2) }}
                                @endif
                                @if((float) $selectedLoan->penalty_balance > 0)
                                    · Penalties UGX {{ number_format((float) $selectedLoan->penalty_balance, 2) }}
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
            </div>
            <div class="rounded border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Selected general ledger account</div>
                <div class="mt-1 font-semibold text-slate-950">{{ $selectedChartAccount?->name ?? 'Select a GL account above' }}</div>
                @if($selectedChartAccount)
                    <div class="text-sm text-slate-500">{{ $selectedChartAccount->code }} · {{ str($selectedChartAccount->type)->headline() }} · {{ str($selectedChartAccount->normal_balance)->headline() }} normal balance · {{ str($selectedChartAccount->drawer_direction ?: 'both')->headline() }}</div>
                @endif
            </div>
        </div>
    </section>
</div>

<div class="mt-6 grid gap-5 {{ $isCashier ? 'xl:grid-cols-1' : 'xl:grid-cols-[1.15fr_0.85fr]' }}">
    <section class="df-panel p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">Post Transaction</h2>
                <p class="df-muted">Post cash to a client account or a branch general ledger account.</p>
            </div>
            <span class="df-badge">UGX 100 minimum denomination</span>
        </div>
        <form method="POST" action="{{ route('teller.post') }}" class="mt-4 grid gap-4 md:grid-cols-2" data-confirm-title="Review transaction" data-confirm-message="Please confirm these details before posting. This helps avoid reversal requests." data-confirm-label="Post transaction" data-confirm-tone="neutral">
            @csrf
            @if($errors->any())
                <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700 md:col-span-2">{{ $errors->first() }}</div>
            @endif
            <input type="hidden" name="account_scope" value="{{ $selectedChartAccount ? 'general_ledger' : 'client_account' }}">
            <input type="hidden" name="account_number" value="{{ $account?->account_number ?? request('account_number') }}">
            <input type="hidden" name="chart_account_id" value="{{ $selectedChartAccount?->id ?? request('chart_account_id') }}">
            <input type="hidden" data-confirm-summary-label="Target" data-confirm-summary-value="{{ $selectedChartAccount ? $selectedChartAccount->code.' · '.$selectedChartAccount->name : ($account ? $account->account_number.' · '.$account->account_name : 'No account selected') }}">
            @if(! $isLedgerPosting)
                <label class="text-sm font-semibold text-slate-700">Client Money Direction <x-required-badge />
                    <select name="transaction_type_id" class="mt-1" data-confirm-summary-label="Transaction type">
                        @foreach($availableTransactionTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <div class="rounded border border-slate-200 bg-slate-50 p-3 text-sm md:col-span-2">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Selected posting target</div>
                @if($selectedChartAccount)
                    <div class="mt-1 font-semibold text-slate-950">{{ $selectedChartAccount->code }} · {{ $selectedChartAccount->name }}</div>
                    <div class="text-slate-500">General Ledger Account · {{ $selectedLedgerEntryType === 'credit' ? 'Money coming in / receipt' : ($selectedLedgerEntryType === 'debit' ? 'Money going out / payment' : 'Choose direction below') }}</div>
                @elseif($account)
                    <div class="mt-1 font-semibold text-slate-950">{{ $account->account_number }} · {{ $account->account_name }}</div>
                    <div class="text-slate-500">{{ $account->accountType?->name }} · {{ $account->client?->full_name ?: $account->client?->business_name }}</div>
                @else
                    <div class="mt-1 text-slate-500">Search and select an account above before posting.</div>
                @endif
            </div>
            @if($isLedgerPosting)
                @if($selectedLedgerEntryType)
                    <input type="hidden" name="gl_entry_type" value="{{ $selectedLedgerEntryType }}">
                    <input type="hidden" data-confirm-summary-label="Money direction" data-confirm-summary-value="{{ $selectedLedgerEntryType === 'credit' ? 'Money coming in / receipt' : 'Money going out / payment' }}">
                @else
                    <label class="text-sm font-semibold text-slate-700">Money Direction <x-required-badge />
                        <select name="gl_entry_type" class="mt-1" data-confirm-summary-label="Money direction">
                            <option value="credit" @selected(old('gl_entry_type') === 'credit')>Money coming in / receipt</option>
                            <option value="debit" @selected(old('gl_entry_type') === 'debit')>Money going out / payment</option>
                        </select>
                    </label>
                @endif
            @endif
            <label class="text-sm font-semibold text-slate-700">Cheque No
                <input name="cheque_number" class="mt-1" data-confirm-summary-label="Cheque no.">
            </label>
            <label class="text-sm font-semibold text-slate-700">Amount <x-required-badge />
                <input name="amount" type="number" min="100" step="100" class="mt-1" required data-confirm-summary-label="Amount">
            </label>
            <label class="text-sm font-semibold text-slate-700">Transaction Date <x-required-badge />
                <input name="transaction_date" type="date" max="{{ today()->toDateString() }}" value="{{ old('transaction_date', today()->toDateString()) }}" class="mt-1" required data-confirm-summary-label="Date">
            </label>
            <label class="text-sm font-semibold text-slate-700 md:col-span-2">Transaction Description
                <textarea name="description" rows="3" class="mt-1" data-confirm-summary-label="Description"></textarea>
            </label>
            <div class="flex flex-wrap gap-2 md:col-span-2">
                <button @disabled(!$session) class="df-action-primary disabled:pointer-events-none disabled:bg-slate-400">Post Transaction</button>
                @unless($isCashier)
                    <a href="{{ route('reports.show', 'cash-balances') }}" class="df-action-secondary">Cash Balance Report</a>
                @endunless
            </div>
        </form>
    </section>

    @unless($isCashier)
    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">{{ today()->format('d-m-Y') }} Installments Due</h2>
            <p class="df-muted">Collections expected at this branch today.</p>
        </div>
        <div class="max-h-[31rem] overflow-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-800 text-white">
                    <tr><th class="p-3">Name</th><th>Telephone</th><th>Account</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    @forelse($dueToday as $schedule)
                        <tr class="border-t border-slate-200">
                            <td class="p-3 font-medium text-slate-950">{{ $schedule->loan->client->full_name }}</td>
                            <td>{{ $schedule->loan->client->phone }}</td>
                            <td>{{ $schedule->loan->loanAccount?->account_number ?? $schedule->loan->loan_number }}</td>
                            <td class="text-right font-semibold">UGX {{ number_format($schedule->outstanding_amount, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-slate-500">No installments due today.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @endunless
</div>

{{-- <div class="mt-6 grid gap-5 xl:grid-cols-[1fr_320px]">
    <section class="df-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Recent Drawer Movement</h2>
            <p class="df-muted">Latest cash in and cash out postings.</p>
        </div>
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3">Time</th><th>Type</th><th>Reference</th><th>Amount</th><th>Balance</th></tr></thead>
            <tbody>
                @forelse($recentMovements as $movement)
                    <tr class="border-t border-slate-200">
                        <td class="p-3">{{ $movement->created_at->format('H:i') }}</td>
                        <td><span class="rounded px-2 py-1 text-xs font-semibold {{ $movement->type === 'cash_in' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ str($movement->type)->headline() }}</span></td>
                        <td>{{ $movement->reference }}</td>
                        <td>UGX {{ number_format($movement->amount, 2) }}</td>
                        <td>UGX {{ number_format($movement->balance_after, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-8 text-center text-slate-500">No drawer movements yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <aside class="df-panel p-5">
        <h2 class="font-semibold text-slate-950">Close & Reconcile</h2>
        <p class="df-muted">Confirm counted cash before ending the session.</p>
        <form method="POST" action="{{ route('teller.close') }}" class="mt-5 grid gap-3">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Closing Balance <x-required-badge />
                <input name="closing_balance" type="number" min="0" step="100" value="{{ $drawer?->current_balance }}" class="mt-1" placeholder="Closing balance" required>
            </label>
            <button @disabled(!$session) class="df-action-danger disabled:pointer-events-none disabled:bg-slate-400">Close Drawer</button>
        </form>
        <div class="mt-5 grid gap-2 text-sm">
            <a href="{{ route('reports.show', 'cash-balances') }}" class="df-action-secondary justify-start">Cash Balances</a>
            <a href="{{ route('reports.show', 'user-posted-transactions') }}" class="df-action-secondary justify-start">Posted Transactions</a>
            <a href="{{ route('reports.show', 'expected-loan-payments') }}" class="df-action-secondary justify-start">Expected Loan Payments</a>
        </div>
    </aside>
</div> --}}

@if($canApproveReversals)
    <section class="df-panel mt-6 overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-950">Pending Transaction Reversals</h2>
            <p class="df-muted">Transaction reversal requests waiting for admin or supervisor approval.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr><th class="p-3">Requested</th><th>Transaction</th><th>Cashier</th><th>Reason</th><th class="text-right">Amount</th><th class="text-right">Decision</th></tr>
                </thead>
                <tbody>
                    @forelse($pendingReversalRequests as $approval)
                        @php
                            $transaction = $approval->subject;
                        @endphp
                        <tr class="border-t border-slate-200 align-top">
                            <td class="p-3">{{ $approval->created_at->format('d M H:i') }}</td>
                            <td>
                                @if($transaction)
                                    <a class="font-semibold text-blue-700" href="{{ route('receipts.show', $transaction) }}">{{ $transaction->reference }}</a>
                                @else
                                    <span class="text-slate-500">Transaction removed</span>
                                @endif
                                <div class="text-xs text-slate-500">{{ $approval->branch?->name }}</div>
                            </td>
                            <td>{{ $approval->requestedBy?->name ?? 'N/A' }}</td>
                            <td class="max-w-md">{{ $approval->reason }}</td>
                            <td class="text-right font-semibold">UGX {{ number_format((float) $approval->amount, 2) }}</td>
                            <td class="text-right">
                                <div class="grid gap-2 md:grid-cols-2">
                                    <form method="POST" action="{{ route('transactions.reversal-requests.approve', $approval) }}" class="grid gap-1">
                                        @csrf
                                        <input name="comments" class="rounded border-slate-300 text-xs" placeholder="Approval note">
                                        <button class="rounded bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('transactions.reversal-requests.reject', $approval) }}" class="grid gap-1">
                                        @csrf
                                        <input name="comments" class="rounded border-slate-300 text-xs" placeholder="Reason" required>
                                        <button class="rounded bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-slate-500">No reversal requests pending.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="df-panel mt-6 overflow-visible">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 class="font-semibold text-slate-950">{{ $isCashier ? "Today's Posted Transactions" : 'Drawer Posted Transactions' }}</h2>
            <p class="df-muted">{{ $isCashier ? 'Search transactions posted by you today.' : 'Search posted transactions by description, reference, client, account, status, or transaction type.' }}</p>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <form method="GET" action="{{ route('teller.index') }}" class="flex flex-wrap items-center gap-2">
                <input name="transaction_search" value="{{ $transactionSearch }}" class="min-w-64 rounded border-slate-300 text-sm" placeholder="Search description or reference">
                <button class="df-action-secondary">Search</button>
                @if($transactionSearch !== '')
                    <a href="{{ route('teller.index') }}" class="rounded border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
                @endif
            </form>
            @unless($isCashier)
                <a href="{{ route('reports.show', ['report' => 'user-posted-transactions', 'search' => $transactionSearch]) }}" class="df-action-secondary">Full Report</a>
            @endunless
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr><th class="p-3">Time</th><th>Reference</th><th>Account</th><th>Target</th><th>Drawer movement</th><th class="text-right">Amount</th><th>Status</th><th class="text-right">Action</th></tr>
            </thead>
            <tbody>
                @forelse($recentTransactions as $transaction)
                    @php
                        $pendingReversal = $transaction->reversalRequests->first();
                        $journalAccount = $transaction->journalEntries->first()?->chartAccount;
                        $drawerMovement = $transaction->drawerSession
                            ? $recentMovements->firstWhere('reference', $transaction->reference)
                            : null;
                    @endphp
                    <tr class="border-t border-slate-200 align-top">
                        <td class="p-3 whitespace-nowrap">{{ $transaction->posted_at?->format('d M H:i') ?? $transaction->created_at->format('d M H:i') }}</td>
                        <td>
                            <a class="font-semibold text-blue-700" href="{{ route('receipts.show', $transaction) }}">{{ $transaction->reference }}</a>
                            @if(filled($transaction->description))
                                <div class="mt-1 max-w-64 text-xs text-slate-500">{{ $transaction->description }}</div>
                            @endif
                        </td>
                        <td class="font-medium text-slate-950">{{ $transaction->account?->account_number ?? ($journalAccount?->code ?? 'Ledger') }}</td>
                        <td class="max-w-56">{{ $transaction->account?->client?->full_name ?? $transaction->account?->client?->business_name ?? ($journalAccount?->name ?? 'General ledger posting') }}</td>
                        <td>
                            @if($drawerMovement)
                                <span class="rounded px-2 py-1 text-xs font-semibold {{ $drawerMovement->type === 'cash_in' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $drawerMovement->type === 'cash_in' ? 'Money came in' : 'Money went out' }}</span>
                                <div class="mt-1 text-xs text-slate-500">{{ $transaction->type?->name ?? 'Drawer posting' }}</div>
                            @else
                                {{ $transaction->type?->name ?? 'Transaction' }}
                            @endif
                        </td>
                        <td class="text-right font-semibold whitespace-nowrap">UGX {{ number_format((float) $transaction->amount, 2) }}</td>
                        <td>
                            @if($pendingReversal)
                                <span class="rounded bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">Reversal pending</span>
                            @elseif($transaction->status === 'reversed')
                                <span class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">Reversed</span>
                            @else
                                <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">{{ str($transaction->status)->headline() }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if($transaction->status === 'posted' && ! $pendingReversal && ! $transaction->reversed_by && (auth()->user()->hasRole('super-admin') || auth()->user()->hasRole('organization-admin') || auth()->user()->hasRole('teller-cashier') || auth()->user()->canPerform('request-reversals') || auth()->user()->canPerform('approve-reversals') || auth()->user()->canPerform('override-maker-checker')))
                                <details class="relative inline-block text-left">
                                    <summary class="cursor-pointer list-none rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">Request reversal</summary>
                                    <form method="POST" action="{{ route('transactions.reversal-requests.store', $transaction) }}" class="absolute right-0 z-20 mt-2 grid w-72 gap-2 rounded-lg border border-amber-200 bg-white p-3 text-left shadow-xl">
                                        @csrf
                                        <label class="text-xs font-semibold text-slate-700">Reason
                                            <textarea name="reason" rows="3" class="mt-1 w-full rounded border-slate-300 text-sm" required></textarea>
                                        </label>
                                        <button class="rounded bg-amber-700 px-3 py-2 text-xs font-semibold text-white">Submit request</button>
                                    </form>
                                </details>
                            @else
                                <a class="text-sm font-semibold text-blue-700" href="{{ route('receipts.show', $transaction) }}">Receipt</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-8 text-center text-slate-500">No posted transactions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="fixed inset-0 z-[90] {{ $hasLookupSearch ? 'grid' : 'hidden' }} place-items-center bg-slate-950/60 p-4" data-teller-lookup-modal data-open="{{ $hasLookupSearch ? '1' : '0' }}">
    <div class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl ring-1 ring-slate-950/10">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-950">Search Account</h3>
                <p class="text-sm text-slate-500">Search, select, and the drawer will load the selected account.</p>
            </div>
            <button type="button" class="rounded border border-slate-200 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-50" data-teller-lookup-close>Close</button>
        </div>
        <form method="GET" action="{{ route('teller.index') }}" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-5 md:grid-cols-[220px_1fr_auto]">
            <select name="lookup_scope" data-teller-lookup-modal-scope required>
                <option value="">Select account type</option>
                @foreach($accountTypes as $type)
                    <option value="account_type:{{ $type->id }}" @selected($lookupScope === 'account_type:'.$type->id || ($selectedAccountTypeId === $type->id && ! $isGeneralLedgerLookup))>{{ $type->name }}</option>
                @endforeach
                <option value="general_ledger" @selected($isGeneralLedgerLookup)>General Ledger Account</option>
            </select>
            <input name="account_search" value="{{ request('account_search') }}" placeholder="Type a name, phone, account number, ledger name, or code" autofocus>
            <button class="df-action-primary justify-center">Search</button>
        </form>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            @if(! $isGeneralLedgerLookup)
                <div class="rounded border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Client account results</div>
                    <div class="mt-3 max-h-[52vh] divide-y divide-slate-200 overflow-y-auto rounded bg-white">
                        @if(! $hasLookupSearch)
                            <div class="p-5 text-sm text-slate-500">Select an account type, type a keyword, then search.</div>
                        @else
                            @forelse($accountResults as $resultAccount)
                                @php
                                    $resultLoan = $resultAccount->loan;
                                    $resultLoanRepayable = $resultLoan
                                        ? (float) $resultLoan->principal_balance + (float) $resultLoan->interest_balance + (float) $resultLoan->fees_balance + (float) $resultLoan->penalty_balance
                                        : null;
                                @endphp
                                <div class="grid gap-3 p-3 sm:grid-cols-[1fr_auto]">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $resultAccount->account_number }} · {{ $resultAccount->account_name }}</div>
                                        <div class="text-sm text-slate-500">{{ $resultAccount->client?->full_name ?: $resultAccount->client?->business_name ?: 'No client' }} · {{ $resultAccount->client?->client_number }} · {{ $resultAccount->client?->phone }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ $resultAccount->accountType?->name }} · {{ str($resultAccount->status)->headline() }} · Balance UGX {{ number_format((float) $resultAccount->balance, 2) }}</div>
                                        @if($resultLoanRepayable !== null)
                                            <div class="mt-2 inline-flex flex-wrap items-center gap-x-2 gap-y-1 rounded border border-brand-200 bg-brand-50 px-2 py-1 text-xs text-brand-900">
                                                <span class="font-semibold">Total to repay UGX {{ number_format($resultLoanRepayable, 2) }}</span>
                                                <span>Interest UGX {{ number_format((float) $resultLoan->interest_balance, 2) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                    <form method="GET" action="{{ route('teller.index') }}" class="flex items-start justify-end" data-teller-lookup-select>
                                        <input type="hidden" name="lookup_scope" value="account_type:{{ $resultAccount->account_type_id }}">
                                        <input type="hidden" name="account_number" value="{{ $resultAccount->account_number }}">
                                        <button class="rounded bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white">Select</button>
                                    </form>
                                </div>
                            @empty
                                <div class="p-5 text-sm text-slate-500">No client accounts matched this search.</div>
                            @endforelse
                        @endif
                    </div>
                </div>
            @endif

            @if($isGeneralLedgerLookup)
                <div class="rounded border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">General ledger results</div>
                    <div class="mt-3 max-h-[52vh] divide-y divide-slate-200 overflow-y-auto rounded bg-white">
                        @if(! $hasLookupSearch)
                            <div class="p-5 text-sm text-slate-500">Select General Ledger Account, type a keyword, then search.</div>
                        @else
                            @forelse($chartAccounts as $chartAccount)
                                <div class="grid gap-3 p-3 sm:grid-cols-[1fr_auto]">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $chartAccount->code }} · {{ $chartAccount->name }}</div>
                                        <div class="text-sm text-slate-500">{{ str($chartAccount->type)->headline() }} · {{ $chartAccount->normal_balance ? str($chartAccount->normal_balance)->headline().' normal balance' : 'Normal balance not set' }} · {{ str($chartAccount->drawer_direction ?: 'both')->headline() }}</div>
                                    </div>
                                    <form method="GET" action="{{ route('teller.index') }}" class="flex items-start justify-end" data-teller-lookup-select>
                                        <input type="hidden" name="lookup_scope" value="general_ledger">
                                        <input type="hidden" name="account_number" value="{{ request('account_number') }}">
                                        <input type="hidden" name="chart_account_id" value="{{ $chartAccount->id }}">
                                        <button class="rounded bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">Select</button>
                                    </form>
                                </div>
                            @empty
                                <div class="p-5 text-sm text-slate-500">No general ledger accounts matched this search.</div>
                            @endforelse
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="fixed inset-0 z-[100] hidden place-items-center bg-slate-950/70 p-4" data-teller-lookup-loading>
    <div class="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-2xl">
        <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-brand-700"></div>
        <div class="mt-4 font-semibold text-slate-950">Loading selected account</div>
        <p class="mt-1 text-sm text-slate-500">Please wait while the drawer is updated.</p>
    </div>
</div>

<x-transaction-receipt-modal :transaction="$receiptTransaction" :close-url="url()->full()" />
@endsection
