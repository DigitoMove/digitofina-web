@extends('layouts.app')

@section('content')
@php
    $client = $loan->client;
    $displayName = $client->full_name ?: $client->business_name ?: $client->client_number;
    $profilePhotoDocument = $client->documents->firstWhere('type', 'profile_photo');
    $loanAccountNumber = $loan->loanAccount?->account_number ?? $loan->loan_number;
    $approvedOrRequestedAmount = (float) ($loan->approved_amount ?? $loan->requested_amount);
    $disbursedAmount = (float) $loan->disbursements->sum('disbursed_amount');
    $netDisbursedAmount = (float) $loan->disbursements->sum('net_amount');
    $paidAmount = (float) $loan->repayments->sum('amount');
    $maturityDate = $loan->schedules->sortByDesc('due_date')->first()?->due_date;
    $totalInstallments = $loan->schedules->count();
    $paidInstallments = $loan->schedules->where('status', 'paid')->count();
    $lateInstallments = $loan->schedules->filter(fn ($schedule) => $schedule->due_date?->isPast() && $schedule->status !== 'paid')->count();
    $unpaidInstallments = max(0, $totalInstallments - $paidInstallments);
    $arrearsDays = (int) $loan->schedules->max('days_overdue');
    $isNonPerforming = in_array($loan->status, ['in_arrears', 'written_off'], true);
    $hasSavingsAccount = $client->accounts->contains(fn ($account) => $account->accountType?->category === 'savings');
    $canCreateLoan = auth()->user()->can('create', \App\Models\Loan::class);
    $canCancel = (auth()->user()->hasRole('super-admin') || auth()->user()->canPerform('manage-users') || auth()->user()->canPerform('disburse-loans')) && ! in_array($loan->status, ['cancelled', 'rejected', 'disbursed', 'active', 'in_arrears', 'fully_paid', 'closed', 'written_off'], true);
    $canUpdateDraftTerms = $loan->status === 'draft' && (auth()->user()->hasRole('super-admin') || auth()->user()->canPerform('manage-users') || auth()->user()->canPerform('disburse-loans'));
    $loanAccountNeedsReopen = $loan->loanAccount && $loan->loanAccount->status !== 'active' && in_array($loan->status, ['disbursed', 'active', 'in_arrears'], true);
    $canReopenClosedLoan = (in_array($loan->status, ['closed', 'fully_paid'], true) || $loanAccountNeedsReopen) && auth()->user()->canAdministerLoanRepairs();
    $canRepairLoanTerms = in_array($loan->status, ['approved', 'disbursed', 'active', 'in_arrears'], true) && auth()->user()->canAdministerLoanRepairs();
    $outstandingLoanBalance = (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance;
    $canClosePaidLoan = $outstandingLoanBalance <= 0 && ! in_array($loan->status, ['closed', 'written_off', 'cancelled', 'rejected'], true) && auth()->user()->canAdministerLoanRepairs();
    $canCloseLoanWithCoverage = $outstandingLoanBalance > 0 && in_array($loan->status, ['disbursed', 'active', 'in_arrears', 'restructured'], true) && auth()->user()->canAdministerLoanRepairs();
    $workflow = ['Draft', 'Appraisal', 'Approval', 'Approved', 'Disbursed', 'Closed'];
    $statusIndex = match (true) {
        $loan->status === 'draft' => 0,
        $loan->status === 'under_review' => 1,
        in_array($loan->status, ['submitted', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification'], true) => 2,
        $loan->status === 'approved' => 3,
        in_array($loan->status, ['disbursed', 'active', 'in_arrears', 'restructured'], true) => 4,
        in_array($loan->status, ['fully_paid', 'closed', 'written_off'], true) => 5,
        default => 2,
    };
    $statusTone = match ($loan->status) {
        'fully_paid', 'closed' => 'bg-blue-100 text-blue-800',
        'written_off', 'cancelled', 'rejected' => 'bg-red-100 text-red-800',
        'in_arrears' => 'bg-rose-100 text-rose-800',
        'active', 'disbursed' => 'bg-emerald-100 text-emerald-800',
        'approved' => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $statusLabel = match ($loan->status) {
        'fully_paid', 'closed' => 'Closed Obligation Met',
        'written_off' => 'Closed Written-Off',
        default => str($loan->status)->headline(),
    };
    $summaryRows = [
        [
            'label' => 'Principal',
            'initial' => max($approvedOrRequestedAmount, (float) $loan->schedules->sum('principal_due')),
            'paid' => (float) $loan->schedules->sum('principal_paid'),
            'overdue' => (float) $loan->schedules->filter(fn ($schedule) => $schedule->due_date?->isPast() && $schedule->status !== 'paid')->sum(fn ($schedule) => max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid)),
            'balance' => (float) $loan->principal_balance,
        ],
        [
            'label' => 'Interest',
            'initial' => (float) $loan->schedules->sum('interest_due'),
            'paid' => (float) $loan->schedules->sum('interest_paid'),
            'overdue' => (float) $loan->schedules->filter(fn ($schedule) => $schedule->due_date?->isPast() && $schedule->status !== 'paid')->sum(fn ($schedule) => max(0, (float) $schedule->interest_due - (float) $schedule->interest_paid)),
            'balance' => (float) $loan->interest_balance,
        ],
        [
            'label' => 'Fees',
            'initial' => (float) $loan->schedules->sum('fees_due'),
            'paid' => (float) $loan->schedules->sum('fees_paid'),
            'overdue' => (float) $loan->schedules->filter(fn ($schedule) => $schedule->due_date?->isPast() && $schedule->status !== 'paid')->sum(fn ($schedule) => max(0, (float) $schedule->fees_due - (float) $schedule->fees_paid)),
            'balance' => (float) $loan->fees_balance,
        ],
        [
            'label' => 'Penalty',
            'initial' => (float) $loan->schedules->sum('penalty_due'),
            'paid' => (float) $loan->schedules->sum('penalty_paid'),
            'overdue' => (float) $loan->schedules->filter(fn ($schedule) => $schedule->due_date?->isPast() && $schedule->status !== 'paid')->sum(fn ($schedule) => max(0, (float) $schedule->penalty_due - (float) $schedule->penalty_paid)),
            'balance' => (float) $loan->penalty_balance,
        ],
    ];
    $actionItems = [
        ['Client Account Dashboard', route('clients.show', $client), 'CD', 0],
        ['Loan Account Details', route('loans.show', $loan), 'LA', null],
        ['Transaction Authorisation Details', route('audit-logs.index', ['search' => $loanAccountNumber]), 'TA', 0],
        ['Disburse Loan', route('loans.show', $loan), 'DL', $loan->status === 'approved' ? 1 : 0],
        ['Assign Officer', route('loans.show', $loan), 'AO', $canAssignOfficer ? 1 : 0],
        ['Loan Account Reports', route('reports.show', 'loan-book'), 'LR', null],
        ['Account Notes', route('loans.show', $loan), 'AN', 0],
        ['Account Notifications', route('loans.show', $loan), 'NO', 0],
    ];
    $accountMenus = [
        ['Savings Accounts', array_filter([! $hasSavingsAccount ? ['Generate savings account', route('clients.savings-accounts.generate', $client), 'post'] : null, ['View accounts', route('clients.savings-accounts', $client), 'get']])],
        ['Loan Accounts', array_filter([$canCreateLoan ? ['New application', route('loans.create', ['client_id' => $client->id])] : null, ['View loans', route('clients.loans', $client)]])],
        ['Current Accounts', [['Open current account', route('accounts.create', ['client_id' => $client->id])], ['View accounts', route('clients.accounts', $client)]]],
        ['Shares Accounts', [['Shares report', route('reports.show', 'share-accounts')], ['Share balances', route('reports.show', 'share-balances')]]],
        ['Term Deposits', [['Maturity report', route('reports.show', 'term-deposits-maturity-report')], ['Funded term deposits', route('reports.show', 'funded-term-deposit-accounts')]]],
    ];
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Loan Account</h1>
        <p class="df-muted">{{ $loanAccountNumber }} · {{ $displayName }} · {{ $loan->product?->name ?? 'Loan product' }}</p>
    </div>
    <span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold {{ $statusTone }}">{{ $statusLabel }}</span>
</div>

@if($canUpdateDraftTerms)
    <section class="df-card mb-5">
        <div class="mb-4">
            <h2 class="text-lg font-semibold text-slate-950">Draft loan terms</h2>
            <p class="df-muted">Adjust imported draft terms before appraisal. The schedule is recalculated immediately.</p>
        </div>
        <form method="POST" action="{{ route('loans.update-draft-terms', $loan) }}" class="grid gap-4 md:grid-cols-4">
            @csrf
            <label class="text-sm font-medium text-slate-700">Loan product
                <select name="loan_product_id" class="mt-1 w-full rounded border-slate-300" required>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected((int) old('loan_product_id', $loan->loan_product_id) === $product->id)>{{ $product->code }} · {{ $product->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700">Requested amount
                <input name="requested_amount" value="{{ old('requested_amount', $loan->requested_amount) }}" type="number" step="100" min="100" class="mt-1 w-full rounded border-slate-300" required>
            </label>
            <label class="text-sm font-medium text-slate-700">Installments
                <input name="term_periods" value="{{ old('term_periods', $loan->term_periods) }}" type="number" min="1" class="mt-1 w-full rounded border-slate-300" required>
            </label>
            <label class="text-sm font-medium text-slate-700">Application date
                <input name="application_date" value="{{ old('application_date', $loan->application_date?->toDateString()) }}" type="date" max="{{ today()->toDateString() }}" class="mt-1 w-full rounded border-slate-300">
            </label>
            <div class="md:col-span-4">
                <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">Update draft and schedule</button>
            </div>
        </form>
    </section>
@endif

@if($canRepairLoanTerms)
    <section class="mb-5 rounded-lg border border-sky-200 bg-sky-50 p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-3xl">
                <h2 class="text-lg font-semibold text-slate-950">Repair loan terms</h2>
                <p class="mt-1 text-sm text-sky-900">Use this for admin corrections such as changing a disbursed loan from weekly installments to monthly installments. Successful repayments must be reversed before schedules can be replaced.</p>
            </div>
            <button type="button" data-loan-repair-open class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-sm ring-1 ring-slate-950 hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Repair loan</button>
        </div>
    </section>

    <div data-loan-repair-modal data-open="{{ $errors->any() && old('loan_product_id') ? '1' : '0' }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
        <div class="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Repair loan terms</h2>
                    <p class="mt-1 text-sm text-slate-600">Update the corrected loan settings, then regenerate the repayment schedule.</p>
                </div>
                <button type="button" data-loan-repair-close class="rounded-lg px-3 py-1 text-2xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="Close repair loan dialog">&times;</button>
            </div>

            <form method="POST" action="{{ route('loans.repair-terms', $loan) }}" class="grid gap-4 md:grid-cols-2" data-confirm-title="Repair loan terms" data-confirm-message="Repair this loan and regenerate its repayment schedule?" data-confirm-label="Repair loan" data-confirm-tone="warning">
                @csrf
                <label class="text-sm font-medium text-slate-700">Loan product
                    <select name="loan_product_id" class="mt-1 w-full rounded border-slate-300" required>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('loan_product_id', $loan->loan_product_id) === $product->id)>{{ $product->code }} · {{ $product->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-slate-700">Approved amount
                    <input name="approved_amount" value="{{ old('approved_amount', $loan->approved_amount ?: $loan->requested_amount) }}" type="number" step="100" min="100" class="mt-1 w-full rounded border-slate-300" required>
                </label>
                <label class="text-sm font-medium text-slate-700">Installments
                    <input name="term_periods" value="{{ old('term_periods', $loan->term_periods) }}" type="number" min="1" class="mt-1 w-full rounded border-slate-300" required>
                </label>
                <label class="text-sm font-medium text-slate-700">Frequency
                    <select name="repayment_frequency" class="mt-1 w-full rounded border-slate-300" required>
                        @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'bi-weekly' => 'Bi-weekly', 'monthly' => 'Monthly'] as $frequency => $label)
                            <option value="{{ $frequency }}" @selected(old('repayment_frequency', $loan->repayment_frequency) === $frequency)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-slate-700">Schedule start
                    <input name="schedule_start_date" value="{{ old('schedule_start_date', ($loan->disbursed_at ?? $loan->approved_at ?? today())->toDateString()) }}" type="date" class="mt-1 w-full rounded border-slate-300" required>
                </label>
                <label class="text-sm font-medium text-slate-700 md:col-span-2">Reason <x-required-badge />
                    <textarea name="reason" rows="3" required class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Explain why the loan terms are being repaired.">{{ old('reason') }}</textarea>
                </label>
                <div class="flex flex-col-reverse gap-2 md:col-span-2 md:flex-row md:justify-end">
                    <button type="button" data-loan-repair-close class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Repair terms and regenerate schedule</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if($canClosePaidLoan || $canCloseLoanWithCoverage || $canReopenClosedLoan)
    <div class="mb-5 grid gap-4 xl:grid-cols-2">
        @if($canClosePaidLoan)
            <section class="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                <div class="flex h-full flex-col gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-emerald-950">Close Fully Paid Loan</h2>
                        <p class="mt-1 text-sm text-emerald-900">This loan has no remaining principal, interest, fees, or penalties, but it is still marked {{ str($loan->status)->headline() }}. Close it and close the linked loan account.</p>
                    </div>
                    <form method="POST" action="{{ route('loans.close-paid', $loan) }}" class="mt-auto grid gap-2" data-confirm-title="Close fully paid loan" data-confirm-message="Close this fully paid loan and close the linked loan account?" data-confirm-label="Close loan" data-confirm-tone="warning">
                        @csrf
                        <textarea name="reason" rows="2" required class="w-full rounded-lg border-emerald-200 bg-white text-sm focus:border-emerald-500 focus:ring-emerald-100" placeholder="Admin reason for closing this paid loan">{{ old('reason') }}</textarea>
                        @error('close_paid_reason')
                            <div class="text-xs font-semibold text-red-700">{{ $message }}</div>
                        @enderror
                        <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Close fully paid loan</button>
                    </form>
                </div>
            </section>
        @endif

        @if($canCloseLoanWithCoverage)
            <section class="rounded-lg border border-rose-200 bg-rose-50 p-5">
                <div class="flex h-full flex-col gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-rose-950">Admin Close With Balance Coverage</h2>
                        <p class="mt-1 text-sm text-rose-900">Outstanding balance is UGX {{ number_format($outstandingLoanBalance, 2) }}. Use this only when management has approved covering or writing off the remaining balance.</p>
                    </div>
                    <button type="button" data-loan-coverage-open class="mt-auto rounded-lg bg-rose-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm ring-1 ring-rose-700 hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">Close with coverage</button>
                </div>
            </section>
        @endif

        @if($canReopenClosedLoan)
            <section class="rounded-lg border border-amber-200 bg-amber-50 p-5">
                <div class="flex h-full flex-col gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-amber-950">{{ $loanAccountNeedsReopen ? 'Reactivate Loan Account' : 'Reopen Loan' }}</h2>
                        <p class="mt-1 text-sm text-amber-900">
                            @if($loanAccountNeedsReopen)
                                The loan is {{ str($loan->status)->headline() }}, but its linked loan account is {{ str($loan->loanAccount->status)->headline() }}. Reactivate it so cashiers can post repayments.
                            @else
                                Use this when a mistaken repayment closed the loan. It reopens the loan account, clears paid installment amounts, restores balances from the schedule, and marks successful repayments as reversed.
                            @endif
                        </p>
                    </div>
                    <button type="button" data-loan-reopen-open class="mt-auto rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-sm ring-1 ring-slate-950 hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">{{ $loanAccountNeedsReopen ? 'Reactivate loan' : 'Reopen loan' }}</button>
                </div>
            </section>
        @endif
    </div>
@endif

@if($canCloseLoanWithCoverage)
    <div data-loan-coverage-modal data-open="{{ $errors->has('coverage_reason') ? '1' : '0' }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
        <div class="w-full max-w-2xl rounded-lg bg-white p-5 shadow-xl">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Close loan with balance coverage</h2>
                    <p class="mt-1 text-sm text-slate-600">The remaining UGX {{ number_format($outstandingLoanBalance, 2) }} will be cleared from the loan account and recorded for audit.</p>
                </div>
                <button type="button" data-loan-coverage-close class="rounded-lg px-3 py-1 text-2xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="Close balance coverage dialog">&times;</button>
            </div>

            <form method="POST" action="{{ route('loans.close-with-coverage', $loan) }}" class="grid gap-4" data-confirm-title="Close loan with coverage" data-confirm-message="Close this loan and clear the remaining balance using the selected coverage method?" data-confirm-label="Close and record coverage" data-confirm-tone="danger">
                @csrf
                <div class="rounded-lg border border-rose-100 bg-rose-50 p-3 text-sm text-rose-900">
                    <div class="flex justify-between gap-3"><span>Principal</span><strong>UGX {{ number_format((float) $loan->principal_balance, 2) }}</strong></div>
                    <div class="flex justify-between gap-3"><span>Interest</span><strong>UGX {{ number_format((float) $loan->interest_balance, 2) }}</strong></div>
                    <div class="flex justify-between gap-3"><span>Fees</span><strong>UGX {{ number_format((float) $loan->fees_balance, 2) }}</strong></div>
                    <div class="flex justify-between gap-3"><span>Penalties</span><strong>UGX {{ number_format((float) $loan->penalty_balance, 2) }}</strong></div>
                </div>
                <label class="text-sm font-semibold text-slate-800">
                    Coverage method <x-required-badge />
                    <select name="coverage_method" required class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm focus:border-brand-500 focus:ring-brand-100">
                        <option value="organization_covered" @selected(old('coverage_method') === 'organization_covered')>Covered by organization</option>
                        <option value="write_off" @selected(old('coverage_method') === 'write_off')>Write off remaining balance</option>
                        <option value="external_settlement" @selected(old('coverage_method') === 'external_settlement')>Settled outside system</option>
                    </select>
                </label>
                <label class="text-sm font-semibold text-slate-800">
                    Reference
                    <input name="coverage_reference" value="{{ old('coverage_reference') }}" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Approval memo, voucher, or external receipt">
                </label>
                <label class="text-sm font-semibold text-slate-800">
                    Reason <x-required-badge />
                    <textarea name="reason" rows="3" required class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="Explain why this balance is being covered">{{ old('reason') }}</textarea>
                </label>
                @error('coverage_reason')
                    <div class="rounded border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $message }}</div>
                @enderror
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" data-loan-coverage-close class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">Close and record coverage</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if($canReopenClosedLoan)
    <div data-loan-reopen-modal data-open="{{ $errors->has('reopen_reason') ? '1' : '0' }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4">
        <div class="w-full max-w-2xl rounded-lg bg-white p-5 shadow-xl">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ $loanAccountNeedsReopen ? 'Reactivate loan' : 'Reopen loan' }}</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        @if($loanAccountNeedsReopen)
                            Confirm the admin reason. The loan account will be activated{{ $loan->status === 'disbursed' ? ' and the loan will move to Active' : '' }}.
                        @else
                            Confirm the admin reason before reopening the loan and clearing paid installment amounts.
                        @endif
                    </p>
                </div>
                <button type="button" data-loan-reopen-close class="rounded-lg px-3 py-1 text-2xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="Close reopen loan dialog">&times;</button>
            </div>

            <form method="POST" action="{{ route('loans.reopen', $loan) }}" class="grid gap-4" data-confirm-title="{{ $loanAccountNeedsReopen ? 'Reactivate loan' : 'Reopen loan' }}" data-confirm-message="{{ $loanAccountNeedsReopen ? 'Reactivate this loan account so repayments can be posted?' : 'Reopen this loan and clear all paid installment amounts?' }}" data-confirm-label="{{ $loanAccountNeedsReopen ? 'Reactivate loan' : 'Reopen loan' }}" data-confirm-tone="warning">
                @csrf
                <label class="text-sm font-semibold text-slate-800">
                    Reason <x-required-badge />
                    <textarea name="reason" rows="3" required class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm focus:border-brand-500 focus:ring-brand-100" placeholder="{{ $loanAccountNeedsReopen ? 'Why is this loan being reactivated?' : 'Why is this loan being reopened?' }}">{{ old('reason') }}</textarea>
                </label>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" data-loan-reopen-close class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">{{ $loanAccountNeedsReopen ? 'Reactivate loan' : 'Reopen loan and clear installments' }}</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="grid gap-5 xl:grid-cols-[22rem_1fr]">
    <aside class="grid gap-5">
        <section class="df-panel p-5">
            <div class="aspect-[4/3] overflow-hidden rounded-card border border-slate-200 bg-slate-100">
                @if($profilePhotoDocument)
                    <img src="{{ route('clients.documents.download', [$client, $profilePhotoDocument]) }}" alt="{{ $displayName }}" class="h-full w-full object-cover">
                @else
                    <div class="grid h-full place-items-center bg-gradient-to-br from-slate-100 to-slate-200 text-6xl font-semibold text-slate-400">{{ str($displayName)->substr(0, 1)->upper() }}</div>
                @endif
            </div>
            <div class="mt-5">
                <h2 class="text-xl font-semibold text-slate-800">{{ $displayName }}</h2>
                <dl class="mt-3 grid gap-1 text-sm text-slate-600">
                    <div><dt class="inline font-semibold">Client Number:</dt> <dd class="inline">{{ $client->client_number }}</dd></div>
                    <div><dt class="inline font-semibold">Client Branch:</dt> <dd class="inline">{{ $client->branch?->name ?? $loan->branch?->name }}</dd></div>
                    <div><dt class="inline font-semibold">Loan Status:</dt> <dd class="inline">{{ $statusLabel }}</dd></div>
                </dl>
            </div>
        </section>

        <section class="df-panel p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Manage Account</h2>
            <div class="mt-3 divide-y divide-slate-100">
                @foreach($actionItems as [$label, $href, $icon, $count])
                    <a href="{{ $href }}" class="flex items-center gap-3 py-2.5 text-sm font-medium text-brand-700 hover:text-brand-900">
                        <span class="grid h-7 w-7 place-items-center rounded bg-slate-100 text-[10px] font-bold text-slate-600">{{ $icon }}</span>
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                        @if(! is_null($count))
                            <span class="rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white">{{ number_format($count) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>

        <section class="df-panel p-5 text-sm text-slate-600">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Address</h2>
            <div class="mt-3 leading-6">
                <div>{{ $client->address ?: 'No physical address recorded.' }}</div>
                <div>{{ $client->village ?: '' }}</div>
                <div>{{ $client->sub_county ?: '' }} {{ $client->district ?: '' }}</div>
                <div>{{ $client->region ?: '' }}</div>
                <div class="mt-2">P1: {{ $client->phone ?: 'N/A' }}</div>
                @if($client->alternative_phone)<div>P2: {{ $client->alternative_phone }}</div>@endif
            </div>
        </section>
    </aside>

    <main class="grid gap-5">
        <div class="flex flex-wrap justify-end gap-2">
            @foreach ($accountMenus as [$label, $links])
                <details class="group relative">
                    <summary class="df-action-primary min-h-10 cursor-pointer list-none rounded-lg px-3 py-2">{{ $label }} <span class="ml-1 text-xs">▾</span></summary>
                    <div class="absolute right-0 z-20 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-2 text-sm shadow-xl">
                        @foreach($links as $link)
                            @php
                                $linkLabel = $link[0];
                                $href = $link[1];
                                $method = $link[2] ?? 'get';
                            @endphp
                            @if($method === 'post')
                                <form method="POST" action="{{ $href }}">
                                    @csrf
                                    <button class="block w-full rounded px-3 py-2 text-left font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-800">{{ $linkLabel }}</button>
                                </form>
                            @else
                                <a href="{{ $href }}" class="block rounded px-3 py-2 font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-800">{{ $linkLabel }}</a>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>

        <section class="df-panel p-5">
            <div class="grid gap-3 md:grid-cols-6">
                @foreach ($workflow as $index => $label)
                    <div class="df-step {{ $index < $statusIndex ? 'is-complete' : '' }} {{ $index === $statusIndex ? 'is-active' : '' }}">
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-white text-xs">{{ $index + 1 }}</span>
                        {{ $label }}
                    </div>
                @endforeach
            </div>
        </section>

        @include('loans.partials.workflow-actions')

        <div data-df-tabs class="grid gap-5">
            <div class="df-tablist" role="tablist">
                <button type="button" class="df-tab" data-df-tab-target="general">General</button>
                <button type="button" class="df-tab" data-df-tab-target="schedule">Loan Schedule</button>
                <button type="button" class="df-tab" data-df-tab-target="repayments">Repayments</button>
                <button type="button" class="df-tab" data-df-tab-target="properties">Properties</button>
            </div>

            <section data-df-tab-panel="general" class="grid gap-5">
                <div class="grid gap-5 xl:grid-cols-[1fr_26rem]">
                    <div class="df-panel bg-slate-50 p-5">
                        <dl class="grid gap-x-8 gap-y-3 text-sm md:grid-cols-2">
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Account Number</dt><dd class="font-semibold text-sky-700">{{ $loanAccountNumber }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Application No.</dt><dd class="font-semibold text-sky-700">{{ $loan->loan_number }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Account Status</dt><dd class="font-semibold text-sky-700">{{ $statusLabel }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Disbursed Amount</dt><dd class="font-semibold text-sky-700">UGX {{ number_format($disbursedAmount, 2) }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Account Branch</dt><dd class="font-semibold text-sky-700">{{ $loan->branch?->name ?? $client->branch?->name ?? 'N/A' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Maturity Date</dt><dd class="font-semibold text-sky-700">{{ $maturityDate?->format('d-m-Y') ?? 'Pending schedule' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Loan Officer</dt><dd class="font-semibold text-sky-700">{{ $loan->officer?->name ?? 'Unassigned' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Created By</dt><dd class="font-semibold text-sky-700">{{ $loan->creator?->name ?? 'System' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Product Name</dt><dd class="font-semibold text-sky-700">{{ $loan->product?->name ?? 'Loan product' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Savings Account</dt><dd class="font-semibold text-sky-700">{{ $loan->savingsAccount?->account_number ?? 'Not generated' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Loan Purpose</dt><dd class="font-semibold text-sky-700">{{ $loan->purpose ?: 'Not recorded' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Loan Account</dt><dd class="font-semibold text-sky-700">{{ $loan->loanAccount?->account_number ?? 'Not generated' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Principal Amount</dt><dd class="font-semibold text-sky-700">UGX {{ number_format($approvedOrRequestedAmount, 2) }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Loan Type</dt><dd class="font-semibold text-sky-700">{{ str($loan->loan_type ?? 'standard')->headline() }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Disburse Date</dt><dd class="font-semibold text-sky-700">{{ $loan->disbursed_at?->format('d-m-Y') ?? 'Not disbursed' }}</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Repayment</dt><dd class="font-semibold text-sky-700">{{ $loan->term_periods }} {{ str($loan->repayment_frequency)->headline() }} installment(s)</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Interest Rate P.A</dt><dd class="font-semibold text-sky-700">{{ number_format((float) $loan->interest_rate, 2) }}%</dd></div>
                            <div class="grid grid-cols-[12rem_1fr] gap-2"><dt class="text-slate-500">Privileged Access</dt><dd class="font-semibold text-sky-700">No</dd></div>
                        </dl>
                    </div>

                    <div class="grid gap-5">
                        <div class="rounded-card bg-sky-100 p-5 text-sm text-sky-900">
                            <dl class="grid gap-3">
                                <div class="flex justify-between gap-3"><dt>Total Installments</dt><dd class="font-semibold">{{ number_format($totalInstallments) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Paid Installments</dt><dd class="font-semibold">{{ number_format($paidInstallments) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Late Installments</dt><dd class="font-semibold">{{ number_format($lateInstallments) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Unpaid Installments</dt><dd class="font-semibold">{{ number_format($unpaidInstallments) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Arrears Days</dt><dd class="font-semibold">{{ number_format($arrearsDays) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Non Performing</dt><dd class="font-semibold">{{ $isNonPerforming ? 'Yes' : 'No' }}</dd></div>
                            </dl>
                        </div>
                        <div class="rounded-card bg-emerald-100 p-5 text-sm text-emerald-900">
                            <dl class="grid gap-3">
                                <div class="flex justify-between gap-3"><dt>Accrued Interest</dt><dd class="font-semibold">UGX {{ number_format((float) $loan->interest_balance, 2) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Payoff Amount</dt><dd class="font-semibold">UGX {{ number_format((float) ($loan->principal_balance + $loan->interest_balance + $loan->fees_balance + $loan->penalty_balance), 2) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt>Net Disbursed</dt><dd class="font-semibold">UGX {{ number_format($netDisbursedAmount, 2) }}</dd></div>
                            </dl>
                        </div>
                    </div>
                </div>

                <div class="df-panel overflow-hidden">
                    <h2 class="px-5 py-4 text-center text-xl font-semibold text-slate-800">Disbursements</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-700 text-xs uppercase text-white">
                                <tr>
                                    <th class="px-4 py-3">No.</th>
                                    <th class="px-4 py-3">Disbursement Date</th>
                                    <th class="px-4 py-3 text-right">Disbursed Amount</th>
                                    <th class="px-4 py-3 text-right">Fees Deducted</th>
                                    <th class="px-4 py-3 text-right">Net Amount</th>
                                    <th class="px-4 py-3">Reversed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($loan->disbursements as $disbursement)
                                    <tr class="odd:bg-white even:bg-slate-50">
                                        <td class="px-4 py-3">{{ $loop->iteration }}</td>
                                        <td class="px-4 py-3">{{ $disbursement->disbursement_date?->format('d-m-Y') ?? 'Pending' }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) $disbursement->disbursed_amount, 2) }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) $disbursement->fees_deducted, 2) }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format((float) $disbursement->net_amount, 2) }}</td>
                                        <td class="px-4 py-3"><span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-700">No</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No disbursement has been posted for this loan yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="df-panel overflow-hidden">
                    <h2 class="px-5 py-4 text-center text-xl font-semibold text-slate-800">Loan Amounts Summary</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-700 text-xs uppercase text-white">
                                <tr>
                                    <th class="px-4 py-3">Description</th>
                                    <th class="px-4 py-3 text-right">Initial Amounts</th>
                                    <th class="px-4 py-3 text-right">Paid Amounts</th>
                                    <th class="px-4 py-3 text-right">Overdue Amounts</th>
                                    <th class="px-4 py-3 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($summaryRows as $row)
                                    <tr class="odd:bg-white even:bg-slate-50">
                                        <td class="px-4 py-3 font-medium text-slate-700">{{ $row['label'] }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format($row['initial'], 2) }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format($row['paid'], 2) }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format($row['overdue'], 2) }}</td>
                                        <td class="px-4 py-3 text-right">UGX {{ number_format($row['balance'], 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="bg-slate-50 font-semibold text-slate-900">
                                    <td class="px-4 py-3">Totals</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format(collect($summaryRows)->sum('initial'), 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format(collect($summaryRows)->sum('paid'), 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format(collect($summaryRows)->sum('overdue'), 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format(collect($summaryRows)->sum('balance'), 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section data-df-tab-panel="schedule" class="df-panel overflow-hidden">
                <h2 class="px-5 py-4 font-semibold text-slate-950">Repayment Schedule</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-700 text-xs uppercase text-white">
                            <tr>
                                <th class="px-4 py-3">#</th>
                                <th class="px-4 py-3">Due date</th>
                                <th class="px-4 py-3 text-right">Principal</th>
                                <th class="px-4 py-3 text-right">Interest</th>
                                <th class="px-4 py-3 text-right">Penalty</th>
                                <th class="px-4 py-3 text-right">Paid</th>
                                <th class="px-4 py-3 text-right">Outstanding</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($loan->schedules as $schedule)
                                <tr class="odd:bg-white even:bg-slate-50">
                                    <td class="px-4 py-3">{{ $schedule->installment_number }}</td>
                                    <td class="px-4 py-3">{{ $schedule->due_date?->format('d-m-Y') ?? 'Pending' }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format((float) $schedule->principal_due, 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format((float) $schedule->interest_due, 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format((float) $schedule->penalty_due, 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format((float) $schedule->total_paid, 2) }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format((float) $schedule->outstanding_amount, 2) }}</td>
                                    <td class="px-4 py-3">{{ str($schedule->status)->headline() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">The repayment schedule has not been generated yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section data-df-tab-panel="repayments" class="df-panel overflow-hidden">
                <h2 class="px-5 py-4 font-semibold text-slate-950">Repayments</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-700 text-xs uppercase text-white">
                            <tr>
                                <th class="px-4 py-3">Receipt</th>
                                <th class="px-4 py-3">Paid Date</th>
                                <th class="px-4 py-3">Received By</th>
                                <th class="px-4 py-3">Method</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($loan->repayments as $repayment)
                                <tr class="odd:bg-white even:bg-slate-50">
                                    <td class="px-4 py-3"><a class="font-semibold text-brand-700" href="{{ route('loan-repayments.receipt', $repayment) }}">{{ $repayment->receipt_number ?? 'Receipt' }}</a></td>
                                    <td class="px-4 py-3">{{ $repayment->paid_at?->format('d-m-Y') }}</td>
                                    <td class="px-4 py-3">{{ $repayment->receivedBy?->name ?? 'System' }}</td>
                                    <td class="px-4 py-3">{{ str($repayment->payment_method)->headline() }}</td>
                                    <td class="px-4 py-3 text-right">UGX {{ number_format((float) $repayment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No repayments have been posted for this loan yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section data-df-tab-panel="properties" class="grid gap-5 xl:grid-cols-2">
                <div class="df-panel p-5">
                    <h2 class="font-semibold text-slate-950">Guarantors</h2>
                    <form method="POST" action="{{ route('loans.guarantors.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">
                        @csrf
                        <label class="text-sm font-semibold text-slate-700">Guarantor type <x-required-badge /><select name="type" required><option value="existing_client">Existing client</option><option value="external">External</option></select></label>
                        <label class="text-sm font-semibold text-slate-700">Existing client <x-required-badge label="Required for existing" class="bg-amber-50 text-amber-700" /><select name="guarantor_client_id"><option value="">Select member</option>@foreach($guarantorClients as $guarantorClient)<option value="{{ $guarantorClient->id }}">{{ $guarantorClient->client_number }} - {{ $guarantorClient->full_name }}</option>@endforeach</select></label>
                        <label class="text-sm font-semibold text-slate-700">Name <x-required-badge /><input name="name" placeholder="Name" required></label>
                        <input name="phone" placeholder="Phone">
                        <input name="national_id" placeholder="National ID">
                        <input name="relationship" placeholder="Relationship">
                        <label class="text-sm font-semibold text-slate-700">Guaranteed amount <x-required-badge /><input name="guaranteed_amount" type="number" step="0.01" placeholder="Guaranteed amount" required></label>
                        <select name="status"><option value="pending">Pending</option><option value="accepted">Accepted</option><option value="rejected">Rejected</option></select>
                        <textarea name="address" class="md:col-span-2" placeholder="Address"></textarea>
                        <button class="df-action-secondary md:col-span-2">Add guarantor</button>
                    </form>
                    <div class="mt-4 divide-y divide-slate-200 text-sm">
                        @forelse($loan->loanGuarantors as $guarantor)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div><div class="font-semibold">{{ $guarantor->name }}</div><div class="text-slate-500">UGX {{ number_format((float) $guarantor->guaranteed_amount, 2) }} · {{ str($guarantor->status)->headline() }}</div></div>
                                <form method="POST" action="{{ route('loans.guarantors.status', [$loan, $guarantor]) }}" class="flex gap-2">@csrf @method('PUT')<select name="status" class="text-sm"><option value="accepted">Accepted</option><option value="rejected">Rejected</option><option value="released">Released</option></select><button class="df-action-secondary px-3 py-1 text-xs">Update</button></form>
                            </div>
                        @empty
                            <div class="py-3 text-sm text-slate-500">No guarantors recorded.</div>
                        @endforelse
                    </div>
                </div>

                <div class="df-panel p-5">
                    <h2 class="font-semibold text-slate-950">Collateral</h2>
                    <form method="POST" action="{{ route('loans.collaterals.store', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">
                        @csrf
                        <label class="text-sm font-semibold text-slate-700">Collateral type <x-required-badge /><select name="type" required>@foreach(['land','vehicle','electronics','household_item','business_asset','livestock','salary','other'] as $type)<option value="{{ $type }}">{{ str($type)->headline() }}</option>@endforeach</select></label>
                        <label class="text-sm font-semibold text-slate-700">Estimated value <x-required-badge /><input name="estimated_value" type="number" step="0.01" placeholder="Estimated value" required></label>
                        <input name="forced_sale_value" type="number" step="0.01" placeholder="Forced sale value">
                        <input name="ownership_proof" placeholder="Ownership proof">
                        <input name="valuation_date" type="date">
                        <select name="status"><option value="pending_verification">Pending verification</option><option value="verified">Verified</option><option value="rejected">Rejected</option></select>
                        <textarea name="description" class="md:col-span-2" placeholder="Description"></textarea>
                        <button class="df-action-secondary md:col-span-2">Add collateral</button>
                    </form>
                    <div class="mt-4 divide-y divide-slate-200 text-sm">
                        @forelse($loan->collaterals as $collateral)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div><div class="font-semibold">{{ str($collateral->type)->headline() }}</div><div class="text-slate-500">UGX {{ number_format((float) $collateral->estimated_value, 2) }} · {{ str($collateral->status)->headline() }}</div></div>
                                <form method="POST" action="{{ route('loans.collaterals.status', [$loan, $collateral]) }}" class="flex gap-2">@csrf @method('PUT')<select name="status" class="text-sm"><option value="verified">Verified</option><option value="rejected">Rejected</option><option value="released">Released</option><option value="seized">Seized</option></select><button class="df-action-secondary px-3 py-1 text-xs">Update</button></form>
                            </div>
                        @empty
                            <div class="py-3 text-sm text-slate-500">No collateral recorded.</div>
                        @endforelse
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
@endsection
