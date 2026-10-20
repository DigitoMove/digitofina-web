@extends('layouts.app')

@section('content')
<div class="mx-auto grid max-w-7xl gap-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-slate-500">Operations</p>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Non-Cash Transactions</h1>
            <p class="mt-1 text-sm text-slate-500">Receive mobile money, merchant-code, bank, and internal payments without changing a teller drawer.</p>
        </div>
        @if(auth()->user()->canPerform('view-reports') && ! auth()->user()->hasRestrictedReportsAccess())
            <a href="{{ route('reports.show', array_filter(['report' => 'non-cash-transactions', 'from' => today()->toDateString(), 'to' => today()->toDateString(), 'branch_id' => $branchId])) }}" class="df-action-secondary">Open Non-Cash Report</a>
        @endif
    </div>

    <section class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
        <div class="font-semibold">Recommended merchant-code setup</div>
        <p class="mt-1">Use a debit-normal <strong>asset</strong> ledger such as "Merchant Code Clearing" or "Mobile Money Clearing", and set its drawer use to <strong>Non-cash only</strong>. Loan principal repayments reduce Loans Receivable; only interest, fees, and penalties are income.</p>
    </section>

    @if($canPostSavingsLoanRepayment)
        <section class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Savings-to-loan internal transfer</div>
                <h2 class="mt-1 text-base font-semibold text-emerald-950">Pay a Client Loan from Savings</h2>
                <p class="mt-1 text-sm text-emerald-800">First find the client’s savings account. The system then shows only that client’s open loans with unpaid installments, so you can make a one-off repayment or set up an automatic deduction without using teller drawer cash.</p>
            </div>

            <form method="GET" action="{{ route('non-cash.index') }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                <input type="hidden" name="account_id" value="{{ $selectedAccount?->id }}">
                <input type="hidden" name="chart_account_id" value="{{ $selectedLedger?->id }}">
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
                <label class="grid gap-1 text-sm">
                    <span class="font-medium text-emerald-950">Search a client savings account</span>
                    <input name="repayment_savings_search" value="{{ $repaymentSavingsSearch }}" class="rounded-lg border-emerald-200 focus:border-emerald-500 focus:ring-emerald-100" placeholder="Client name, phone, client number, or savings account number">
                </label>
                <button class="df-action-secondary">Search savings</button>
            </form>

            @if($repaymentSavingsSearch !== '')
                <div class="mt-4 divide-y divide-emerald-200 overflow-hidden rounded-lg border border-emerald-200 bg-white">
                    @forelse($repaymentSavingsResults as $savingsAccount)
                        @php
                            $isAccountSource = $savingsAccount->repayment_source_type === 'account';
                            $availableBalance = (float) ($isAccountSource ? $savingsAccount->balance : $savingsAccount->available_balance);
                            $sourceLabel = $isAccountSource ? ($savingsAccount->accountType?->name ?? 'Savings account') : ($savingsAccount->product?->name ?? 'Savings account');
                            $selection = $isAccountSource ? ['repayment_account_id' => $savingsAccount->id] : ['repayment_savings_account_id' => $savingsAccount->id];
                        @endphp
                        <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                            <div>
                                <div class="font-semibold text-slate-950">{{ $savingsAccount->client?->full_name ?? $savingsAccount->client?->business_name ?? 'Client' }}</div>
                                <div class="text-sm text-slate-600">{{ $savingsAccount->account_number }} · {{ $sourceLabel }}</div>
                                <div class="mt-1 text-xs font-semibold {{ $availableBalance > 0 ? 'text-emerald-700' : 'text-amber-700' }}">Available to transfer: UGX {{ number_format($availableBalance, 2) }}</div>
                            </div>
                            <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except(['repayment_savings_search', 'repayment_loan_id', 'repayment_account_id', 'repayment_savings_account_id']), $selection))) }}" class="df-action-primary justify-center">Use this savings</a>
                        </div>
                    @empty
                        <div class="p-5 text-sm text-slate-500">No active savings accounts match that search in the selected branch.</div>
                    @endforelse
                </div>
            @endif

            @if($selectedRepaymentSource)
                @php
                    $selectedSavingsBalance = (float) ($selectedRepaymentAccount ? $selectedRepaymentAccount->balance : $selectedRepaymentSavings->available_balance);
                    $selectedSavingsLabel = $selectedRepaymentAccount ? ($selectedRepaymentAccount->accountType?->name ?? 'Savings account') : ($selectedRepaymentSavings->product?->name ?? 'Savings account');
                @endphp
                <div class="mt-4 rounded-lg border border-emerald-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Selected savings account</div>
                            <div class="mt-1 font-semibold text-slate-950">{{ $selectedRepaymentSource->account_number }} · {{ $selectedRepaymentSource->client?->full_name ?? $selectedRepaymentSource->client?->business_name }}</div>
                            <div class="mt-1 text-sm text-slate-600">{{ $selectedSavingsLabel }} · Available to transfer: <span class="font-semibold">UGX {{ number_format($selectedSavingsBalance, 2) }}</span></div>
                        </div>
                        <a href="{{ route('non-cash.index', array_filter(request()->except(['repayment_account_id', 'repayment_savings_account_id', 'repayment_loan_id', 'repayment_savings_search']))) }}" class="df-action-secondary">Change savings</a>
                    </div>

                    @if($repaymentLoans->isNotEmpty())
                        <form method="GET" action="{{ route('non-cash.index') }}" class="mt-4 grid gap-3 border-t border-emerald-100 pt-4 md:grid-cols-[1fr_auto] md:items-end">
                            <input type="hidden" name="account_id" value="{{ $selectedAccount?->id }}">
                            <input type="hidden" name="chart_account_id" value="{{ $selectedLedger?->id }}">
                            <input type="hidden" name="branch_id" value="{{ $branchId }}">
                            @if($selectedRepaymentAccount)
                                <input type="hidden" name="repayment_account_id" value="{{ $selectedRepaymentAccount->id }}">
                            @else
                                <input type="hidden" name="repayment_savings_account_id" value="{{ $selectedRepaymentSavings->id }}">
                            @endif
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium text-emerald-950">Payable loan for this client</span>
                                <select name="repayment_loan_id" required class="rounded-lg border-emerald-200 focus:border-emerald-500 focus:ring-emerald-100" onchange="this.form.submit()">
                                    <option value="">Choose a loan with an unpaid installment</option>
                                    @foreach($repaymentLoans as $repaymentLoan)
                                        @php
                                            $nextInstallment = $repaymentLoan->schedules->first();
                                            $unpaidAmount = (float) $repaymentLoan->schedules->sum('outstanding_amount');
                                        @endphp
                                        <option value="{{ $repaymentLoan->id }}" @selected($selectedRepaymentLoan?->id === $repaymentLoan->id)>{{ $repaymentLoan->loan_number }} · {{ $repaymentLoan->product?->name ?? 'Loan' }} · UGX {{ number_format($unpaidAmount, 2) }} unpaid{{ $nextInstallment?->due_date ? ' · next due '.$nextInstallment->due_date->format('d M Y') : '' }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="df-action-secondary">Load loan</button>
                        </form>
                    @else
                        <p class="mt-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">This client has no active or in-arrears loans with unpaid installments in this branch.</p>
                    @endif
                </div>
            @endif

            @if($selectedRepaymentSource && $selectedRepaymentLoan)
                @php
                    $nextInstallment = $selectedRepaymentLoan->schedules->first();
                    $unpaidAmount = (float) $selectedRepaymentLoan->schedules->sum('outstanding_amount');
                    $repaymentMaximum = min($selectedSavingsBalance, $unpaidAmount);
                @endphp
                <div class="mt-4 rounded-lg border border-emerald-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Selected loan</div>
                    <div class="mt-1 font-semibold text-slate-950">{{ $selectedRepaymentLoan->loan_number }} · {{ $selectedRepaymentLoan->product?->name ?? 'Loan' }} · {{ str($selectedRepaymentLoan->status)->headline() }}</div>
                    <div class="mt-1 text-sm text-slate-600">Unpaid installments: <span class="font-semibold">UGX {{ number_format($unpaidAmount, 2) }}</span>{{ $nextInstallment?->due_date ? ' · Earliest unpaid installment due '.$nextInstallment->due_date->format('d M Y') : '' }}</div>
                    <p class="mt-2 text-xs text-emerald-800">Each payment clears the earliest unpaid installment first: penalties, then fees, interest, and principal.</p>
                </div>

                <form method="POST" action="{{ route('non-cash.repay-loan-from-savings') }}" class="mt-4 grid gap-4 border-t border-emerald-200 pt-4 sm:grid-cols-[1fr_auto] sm:items-end" data-confirm-title="Review savings loan repayment" data-confirm-message="Transfer the selected amount from this savings account to this same-client loan? No teller drawer cash will move." data-confirm-label="Post repayment" data-confirm-tone="neutral">
                    @csrf
                    @if($selectedRepaymentAccount)
                        <input type="hidden" name="account_id" value="{{ $selectedRepaymentAccount->id }}">
                    @else
                        <input type="hidden" name="savings_account_id" value="{{ $selectedRepaymentSavings->id }}">
                    @endif
                    <input type="hidden" name="loan_id" value="{{ $selectedRepaymentLoan->id }}">
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium text-emerald-950">One-off repayment amount</span>
                        <input type="number" name="amount" min="100" max="{{ number_format($repaymentMaximum, 2, '.', '') }}" step="100" value="{{ old('amount') }}" required @disabled($repaymentMaximum < 100) class="rounded-lg border-emerald-200 focus:border-emerald-500 focus:ring-emerald-100 disabled:bg-slate-100" placeholder="UGX 100 or more">
                        <span class="text-xs text-emerald-800">Maximum transferable now: UGX {{ number_format($repaymentMaximum, 2) }}.</span>
                    </label>
                    <button @disabled($repaymentMaximum < 100) class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-400">Post Savings Repayment</button>
                </form>

                @if($canConfigureScheduledDeductions)
                    <div class="mt-5 border-t border-violet-200 pt-5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-violet-700">Automatic internal transfer</div>
                        <h3 class="mt-1 font-semibold text-violet-950">Schedule Deductions for this Same Client Pair</h3>
                        <p class="mt-1 text-sm text-violet-800">Use the selected savings account and loan. An administrator activates the schedule. Each run transfers the set amount, or only the final remaining installment amount, and stops once the loan is cleared.</p>
                        <form method="POST" action="{{ route('non-cash.scheduled-deductions.store') }}" class="mt-4 grid gap-4 lg:grid-cols-4">
                            @csrf
                            @if($selectedRepaymentAccount)
                                <input type="hidden" name="account_id" value="{{ $selectedRepaymentAccount->id }}">
                            @else
                                <input type="hidden" name="savings_account_id" value="{{ $selectedRepaymentSavings->id }}">
                            @endif
                            <input type="hidden" name="loan_id" value="{{ $selectedRepaymentLoan->id }}">
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium text-violet-950">Amount per run (UGX)</span>
                                <input type="number" name="amount" min="100" step="100" value="{{ old('amount') }}" required class="rounded-lg border-violet-200 focus:border-violet-500 focus:ring-violet-100" placeholder="5,000">
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium text-violet-950">Frequency</span>
                                <select name="frequency" required class="rounded-lg border-violet-200 focus:border-violet-500 focus:ring-violet-100">
                                    @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Every two weeks', 'monthly' => 'Monthly'] as $frequency => $label)
                                        <option value="{{ $frequency }}" @selected(old('frequency', 'monthly') === $frequency)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span class="font-medium text-violet-950">First run</span>
                                <input type="datetime-local" name="next_run_at" value="{{ old('next_run_at', now()->format('Y-m-d\TH:i')) }}" required class="rounded-lg border-violet-200 focus:border-violet-500 focus:ring-violet-100">
                            </label>
                            <div class="flex items-end">
                                <button class="w-full rounded-lg bg-violet-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-800">Create Pending Schedule</button>
                            </div>
                        </form>
                    </div>
                @endif
            @endif
        </section>
    @endif

    @if($scheduledDeductionSchedules->isNotEmpty())
        <section class="overflow-hidden rounded-lg border border-violet-200 bg-white shadow-sm">
            <div class="border-b border-violet-100 bg-violet-50 p-5">
                <h2 class="font-semibold text-violet-950">Scheduled Savings-to-Loan Deductions</h2>
                <p class="mt-1 text-sm text-violet-800">Every attempt is recorded with its loan repayment history.</p>
            </div>
            <div class="overflow-auto">
                <table class="w-full min-w-[1050px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Client / Accounts</th>
                            <th class="px-4 py-3">Amount & frequency</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Next run</th>
                            <th class="px-4 py-3">Last result</th>
                            <th class="px-4 py-3 text-right">Admin action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($scheduledDeductionSchedules as $schedule)
                            <tr class="border-t border-slate-200 align-top">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950">{{ $schedule->client?->full_name ?? $schedule->client?->business_name ?? 'Client' }}</div>
                                    <div class="text-xs text-slate-500">{{ $schedule->account?->account_number ?? $schedule->savingsAccount?->account_number }} → {{ $schedule->loan?->loan_number }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">UGX {{ number_format((float) $schedule->amount, 2) }} · {{ str($schedule->frequency)->headline() }}</td>
                                <td class="px-4 py-3"><span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ str($schedule->status)->headline() }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $schedule->next_run_at?->format('d M Y H:i') }}</td>
                                <td class="max-w-xs px-4 py-3">
                                    <div class="font-semibold {{ $schedule->last_result === 'succeeded' ? 'text-emerald-700' : ($schedule->last_result === 'failed' ? 'text-red-700' : 'text-amber-700') }}">{{ $schedule->last_result ? str($schedule->last_result)->headline() : 'Not run yet' }}</div>
                                    <div class="text-xs text-slate-500">{{ $schedule->last_message }}</div>
                                    @if($schedule->runs->isNotEmpty())
                                        <details class="mt-1 text-xs text-slate-600">
                                            <summary class="cursor-pointer font-semibold">{{ $schedule->runs->count() }} recorded run(s)</summary>
                                            <div class="mt-1 grid gap-1">
                                                @foreach($schedule->runs as $run)
                                                    <div>{{ $run->due_at?->format('d M Y H:i') }} · {{ str($run->status)->headline() }}{{ $run->failure_reason ? ' · '.$run->failure_reason : '' }}</div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($canAdministerScheduledDeductions && in_array($schedule->status, ['draft', 'disabled'], true))
                                        <form method="POST" action="{{ route('non-cash.scheduled-deductions.activate', $schedule) }}" class="inline">
                                            @csrf
                                            <button class="rounded bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white">Activate</button>
                                        </form>
                                    @endif
                                    @if($canAdministerScheduledDeductions && $schedule->status === 'active')
                                        <form method="POST" action="{{ route('non-cash.scheduled-deductions.disable', $schedule) }}" class="inline">
                                            @csrf
                                            <button class="rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">Disable</button>
                                        </form>
                                    @endif
                                    @if($canAdministerScheduledDeductions && $schedule->status !== 'cancelled')
                                        <form method="POST" action="{{ route('non-cash.scheduled-deductions.cancel', $schedule) }}" class="mt-1 inline">
                                            @csrf
                                            <button class="rounded bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">Cancel</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">1. Client account</div>
                    <div class="mt-2 font-semibold text-slate-950">{{ $selectedAccount?->account_number ?? 'No account selected' }}</div>
                    <div class="text-sm text-slate-500">{{ $selectedAccount?->client?->full_name ?? $selectedAccount?->account_name ?? 'Search by client, phone, or account number.' }}</div>
                    @if($selectedAccount)
                        <div class="mt-2 text-xs text-slate-500">{{ $selectedAccount->accountType?->name }} · Balance UGX {{ number_format((float) $selectedAccount->balance, 2) }}</div>
                    @endif
                </div>
                <button type="button" class="df-action-secondary shrink-0" data-non-cash-lookup-open="account">{{ $selectedAccount ? 'Change' : 'Search' }}</button>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">2. Payment / offset ledger</div>
                    <div class="mt-2 font-semibold text-slate-950">{{ $selectedLedger?->name ?? 'No ledger selected' }}</div>
                    <div class="text-sm text-slate-500">{{ $selectedLedger ? $selectedLedger->code.' · '.str($selectedLedger->type)->headline().' · '.str($selectedLedger->normal_balance)->headline().' normal' : 'Search for the non-cash payment-channel ledger.' }}</div>
                    @if($selectedLedger && ($selectedLedger->type !== 'asset' || $selectedLedger->normal_balance !== 'debit'))
                        <div class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Loan repayments require a debit-normal asset ledger.</div>
                    @elseif($selectedLedger && $selectedLedger->drawer_direction !== 'none')
                        <div class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">Set Drawer Use to "Non-cash only" to keep this ledger out of teller drawers.</div>
                    @endif
                </div>
                <button type="button" class="df-action-secondary shrink-0" data-non-cash-lookup-open="ledger">{{ $selectedLedger ? 'Change' : 'Search' }}</button>
            </div>
        </section>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">3. Post transaction</div>
            <h2 class="mt-1 text-base font-semibold text-slate-900">Record Non-Cash Payment</h2>
        </div>
        <form method="POST" action="{{ route('non-cash.store') }}" class="mt-4 grid gap-4 lg:grid-cols-4" data-confirm-title="Review non-cash transaction" data-confirm-message="Please confirm these details before posting. This helps avoid reversal requests." data-confirm-label="Post non-cash transaction" data-confirm-tone="neutral">
            @csrf
            <input type="hidden" name="account_id" value="{{ old('account_id', $selectedAccount?->id) }}">
            <input type="hidden" name="chart_account_id" value="{{ old('chart_account_id', $selectedLedger?->id) }}">
            <input type="hidden" data-confirm-summary-label="Account" data-confirm-summary-value="{{ $selectedAccount ? $selectedAccount->account_number.' · '.($selectedAccount->client?->full_name ?? $selectedAccount->account_name) : 'None selected' }}">
            <input type="hidden" data-confirm-summary-label="Offset ledger" data-confirm-summary-value="{{ $selectedLedger ? $selectedLedger->code.' · '.$selectedLedger->name : 'None selected' }}">

            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Transaction type</span>
                <select name="transaction_type_id" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" data-confirm-summary-label="Transaction type">
                    <option value="">Choose type</option>
                    @foreach($transactionTypes as $type)
                        <option value="{{ $type->id }}" @selected((int) old('transaction_type_id') === $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Transaction date</span>
                <input type="date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" data-confirm-summary-label="Date">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Amount</span>
                <input type="number" name="amount" value="{{ old('amount') }}" min="100" step="100" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="100" data-confirm-summary-label="Amount">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Ledger charges</span>
                <input type="number" name="charges" value="{{ old('charges', 0) }}" min="0" step="100" class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" data-confirm-summary-label="Charges">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Charge handling</span>
                <select name="charge_mode" class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" data-confirm-summary-label="Charge handling">
                    @foreach($chargeModes as $mode => $label)
                        <option value="{{ $mode }}" @selected(old('charge_mode', 'deduct_from_amount') === $mode)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium text-slate-700">Reason / narration</span>
                <input name="description" value="{{ old('description') }}" required class="rounded-lg border-slate-200 focus:border-brand-500 focus:ring-brand-100" placeholder="Merchant code repayment, bank transfer..." data-confirm-summary-label="Reason">
            </label>
            <div class="lg:col-span-4">
                <button class="rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300" @disabled(! $selectedAccount || ! $selectedLedger)>Post Non-Cash Transaction</button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-5">
            <div>
                <h2 class="font-semibold text-slate-950">Recent Non-Cash History</h2>
                <p class="text-sm text-slate-500">Who paid, when it was posted, the payment ledger used, and the amount.</p>
            </div>
            <form method="GET" action="{{ route('non-cash.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="account_id" value="{{ $selectedAccount?->id }}">
                <input type="hidden" name="chart_account_id" value="{{ $selectedLedger?->id }}">
                <input name="history_search" value="{{ $historySearch }}" class="min-w-64 rounded border-slate-300 text-sm" placeholder="Client, account, reference, or ledger">
                <input name="history_from" type="date" value="{{ $historyFrom }}" class="rounded border-slate-300 text-sm" aria-label="History from date">
                <input name="history_to" type="date" value="{{ $historyTo }}" class="rounded border-slate-300 text-sm" aria-label="History to date">
                <button class="df-action-secondary">Filter</button>
            </form>
        </div>
        <div class="overflow-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Date & Time</th>
                        <th class="px-4 py-3">Paid For</th>
                        <th class="px-4 py-3">Payment Ledger</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Posted By</th>
                        <th class="px-4 py-3">Reference</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentTransactions as $transaction)
                        @php
                            $paymentLedger = $transaction->loanRepayment
                                ? $transaction->journalEntries->firstWhere('entry_type', 'debit')?->chartAccount
                                : $transaction->journalEntries->first(fn ($entry) => str($entry->journal_number)->startsWith('NC'))?->chartAccount;
                        @endphp
                        <tr class="border-t border-slate-200 align-top">
                            <td class="whitespace-nowrap px-4 py-3">{{ $transaction->posted_at?->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-950">{{ $transaction->account?->client?->full_name ?? $transaction->account?->account_name ?? 'N/A' }}</div>
                                <div class="text-xs text-slate-500">{{ $transaction->account?->account_number }}{{ $transaction->loanRepayment?->loan ? ' · '.$transaction->loanRepayment->loan->loan_number : '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">{{ $paymentLedger?->name ?? 'Unmapped' }}</div>
                                <div class="text-xs text-slate-500">{{ $paymentLedger?->code }}</div>
                            </td>
                            <td class="px-4 py-3">{{ $transaction->type?->name ?? 'N/A' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-950">
                                <div>UGX {{ number_format((float) $transaction->amount, 2) }}</div>
                                @if((float) $transaction->charges > 0)
                                    <div class="text-xs font-medium text-amber-700">Charges UGX {{ number_format((float) $transaction->charges, 2) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $transaction->user?->name ?? 'System' }}</td>
                            <td class="px-4 py-3"><a href="{{ route('receipts.show', $transaction) }}" class="font-semibold text-brand-700 hover:underline">{{ $transaction->reference }}</a></td>
                            <td class="px-4 py-3 text-right">
                                @php($pendingReversal = $transaction->reversalRequests->first())
                                @if($pendingReversal)
                                    <span class="whitespace-nowrap rounded bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">Reversal pending</span>
                                @elseif($transaction->status === 'reversed')
                                    <span class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">Reversed</span>
                                @elseif(auth()->user()->hasRole('super-admin') || auth()->user()->hasRole('organization-admin') || auth()->user()->hasRole('teller-cashier') || auth()->user()->canPerform('request-reversals') || auth()->user()->canPerform('approve-reversals') || auth()->user()->canPerform('override-maker-checker'))
                                    <details class="relative inline-block text-left">
                                        <summary class="cursor-pointer list-none whitespace-nowrap rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">Request reversal</summary>
                                        <form method="POST" action="{{ route('transactions.reversal-requests.store', $transaction) }}" class="absolute right-0 z-20 mt-2 grid w-72 gap-2 rounded-lg border border-amber-200 bg-white p-3 text-left shadow-xl">
                                            @csrf
                                            <label class="text-xs font-semibold text-slate-700">Reason
                                                <textarea name="reason" rows="3" class="mt-1 w-full rounded border-slate-300 text-sm" required></textarea>
                                            </label>
                                            <button class="rounded bg-amber-700 px-3 py-2 text-xs font-semibold text-white">Submit request</button>
                                        </form>
                                    </details>
                                @else
                                    <a href="{{ route('receipts.show', $transaction) }}" class="text-sm font-semibold text-brand-700">Receipt</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No non-cash transactions matched these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="fixed inset-0 z-[90] {{ request('account_search') ? 'grid' : 'hidden' }} place-items-center bg-slate-950/60 p-4" data-non-cash-lookup-modal="account" data-open="{{ request('account_search') ? '1' : '0' }}">
    <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div><h3 class="text-lg font-semibold text-slate-950">Search Client Account</h3><p class="text-sm text-slate-500">Search and select without navigating a wide table.</p></div>
            <button type="button" class="df-action-secondary" data-non-cash-lookup-close>Close</button>
        </div>
        <form method="GET" action="{{ route('non-cash.index') }}" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-5 md:grid-cols-[220px_1fr_auto]">
            <input type="hidden" name="chart_account_id" value="{{ $selectedLedger?->id }}">
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <select name="account_type_id">
                <option value="">All account types</option>
                @foreach($accountTypes as $type)
                    <option value="{{ $type->id }}" @selected($selectedAccountTypeId === $type->id)>{{ $type->name }}</option>
                @endforeach
            </select>
            <input name="account_search" value="{{ request('account_search') }}" placeholder="Name, phone, client number, or account number" autofocus>
            <button class="df-action-primary justify-center">Search</button>
        </form>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div class="divide-y divide-slate-200 rounded border border-slate-200">
                @forelse($accountResults as $account)
                    <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto]">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $account->account_number }} · {{ $account->client?->full_name ?? $account->account_name }}</div>
                            <div class="text-sm text-slate-500">{{ $account->accountType?->name }} · {{ $account->branch?->name }} · Balance UGX {{ number_format((float) $account->balance, 2) }}</div>
                        </div>
                        <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except(['account_search', 'ledger_search']), ['account_id' => $account->id, 'chart_account_id' => $selectedLedger?->id]))) }}" class="df-action-primary self-start">Select</a>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-slate-500">Type a keyword to find an account.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="fixed inset-0 z-[90] {{ request('ledger_search') ? 'grid' : 'hidden' }} place-items-center bg-slate-950/60 p-4" data-non-cash-lookup-modal="ledger" data-open="{{ request('ledger_search') ? '1' : '0' }}">
    <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div><h3 class="text-lg font-semibold text-slate-950">Search Payment Ledger</h3><p class="text-sm text-slate-500">For merchant-code loan payments, choose a debit-normal asset ledger marked non-cash only.</p></div>
            <button type="button" class="df-action-secondary" data-non-cash-lookup-close>Close</button>
        </div>
        <form method="GET" action="{{ route('non-cash.index') }}" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-5 md:grid-cols-[1fr_auto]">
            <input type="hidden" name="account_id" value="{{ $selectedAccount?->id }}">
            <input type="hidden" name="account_type_id" value="{{ $selectedAccountTypeId }}">
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <input name="ledger_search" value="{{ request('ledger_search') }}" placeholder="Ledger name, code, or type" autofocus>
            <button class="df-action-primary justify-center">Search</button>
        </form>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div class="divide-y divide-slate-200 rounded border border-slate-200">
                @forelse($ledgerResults as $ledger)
                    <div class="grid gap-3 p-4 sm:grid-cols-[1fr_auto]">
                        <div>
                            <div class="flex flex-wrap items-center gap-2 font-semibold text-slate-950">
                                <span>{{ $ledger->code }} · {{ $ledger->name }}</span>
                                @if($ledger->type === 'asset' && $ledger->normal_balance === 'debit' && $ledger->drawer_direction === 'none')
                                    <span class="rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-700">Recommended for payment channels</span>
                                @endif
                            </div>
                            <div class="text-sm text-slate-500">{{ str($ledger->type)->headline() }} · {{ str($ledger->normal_balance)->headline() }} normal · {{ $ledger->drawer_direction === 'none' ? 'Non-cash only' : 'Available to drawer' }} · {{ $ledger->branch?->name }}</div>
                        </div>
                        <a href="{{ route('non-cash.index', array_filter(array_merge(request()->except(['account_search', 'ledger_search']), ['account_id' => $selectedAccount?->id, 'chart_account_id' => $ledger->id]))) }}" class="df-action-primary self-start">Select</a>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-slate-500">Type a keyword to find a ledger account.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<x-transaction-receipt-modal :transaction="$receiptTransaction" :close-url="route('non-cash.index')" />
@endsection
