@php
    $currentUser = auth()->user();
    $canManageLoanWorkflow = $currentUser->canPerform('manage-loans');
    $canManageLoanAccounts = $currentUser->canPerform('manage-accounts') || $currentUser->canPerform('manage-loans');
    $canApproveLoan = $currentUser->canPerform('approve-loans');
    $canDisburseLoan = $currentUser->canPerform('disburse-loans');
    $canPostLoanRepayment = $currentUser->canPerform('post-repayments');
    $hasVisibleWorkflowAction = $canAssignOfficer
        || $canCancel
        || ($canManageLoanAccounts && $loan->status === 'draft')
        || ($canManageLoanWorkflow && $loan->status === 'under_review')
        || ($canApproveLoan && in_array($loan->status, ['submitted', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification'], true))
        || ($canDisburseLoan && $loan->status === 'approved')
        || ($canPostLoanRepayment && in_array($loan->status, ['active', 'in_arrears'], true));
@endphp

<section class="df-panel p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold text-slate-950">Workflow Actions</h2>
            <p class="mt-1 text-sm text-slate-500">Use these controls to move this loan through account creation, appraisal, approval, disbursement, and repayment.</p>
        </div>
        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusTone }}">{{ $statusLabel }}</span>
    </div>

    @foreach(['workflow', 'cancel_reason', 'officer_id', 'approved_amount', 'reason', 'payment_method'] as $errorKey)
        @if($errors->has($errorKey))
            <div class="mt-4 rounded border border-coral-500/20 bg-coral-50 p-3 text-sm text-coral-700">{{ $errors->first($errorKey) }}</div>
        @endif
    @endforeach

    @if($canAssignOfficer)
        <form method="POST" action="{{ route('loans.assign-officer', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-[1fr_auto]">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Assigned officer <x-required-badge />
                <select name="officer_id" class="mt-1 rounded border-slate-300" required>
                    @foreach($assignableOfficers as $officer)
                        <option value="{{ $officer->id }}" @selected((int) old('officer_id', $loan->officer_id) === $officer->id)>{{ $officer->name }} · {{ $officer->role?->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="df-action-secondary">Update Assigned Officer</button>
        </form>
    @endif

    @if($canCancel)
        <form method="POST" action="{{ route('loans.cancel', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-coral-500/20 bg-coral-50 p-4 md:grid-cols-[1fr_auto]">
            @csrf
            <label class="text-sm font-semibold text-coral-800">Cancellation reason <x-required-badge /><input name="reason" value="{{ old('reason') }}" placeholder="Cancellation reason" class="mt-1 rounded border-coral-200" required></label>
            <button class="df-action-danger">Cancel Loan</button>
        </form>
    @endif

    @if($canManageLoanAccounts && $loan->status === 'draft')
        <div class="mt-4 grid gap-4 lg:grid-cols-4">
            <form method="POST" action="{{ route('loans.generate-savings-account', $loan) }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                @csrf
                <div class="text-sm font-semibold text-slate-950">Savings account</div>
                <div class="text-sm text-slate-500">{{ $loan->savingsAccount?->account_number ?? 'Create or attach client savings' }}</div>
                <button @disabled((bool) $loan->savings_account_id) class="df-action-secondary disabled:pointer-events-none disabled:bg-slate-300">Generate</button>
            </form>
            <form method="POST" action="{{ route('loans.generate-loan-account', $loan) }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                @csrf
                <div class="text-sm font-semibold text-slate-950">Loan account</div>
                <div class="text-sm text-slate-500">{{ $loan->loanAccount?->account_number ?? 'Create repayment account' }}</div>
                <button @disabled((bool) $loan->loan_account_id) class="df-action-secondary disabled:pointer-events-none disabled:bg-slate-300">Generate</button>
            </form>
            <form method="POST" action="{{ route('loans.submit-appraisal', $loan) }}" class="grid gap-3 rounded-lg border border-brand-200 bg-brand-50 p-4 lg:col-span-2">
                @csrf
                <div class="text-sm font-semibold text-slate-950">Appraisal</div>
                <div class="text-sm text-slate-500">Move this application to officer appraisal after both accounts exist.</div>
                <button class="df-action-primary">Submit for Appraisal</button>
            </form>
        </div>
    @endif

    @if($canManageLoanWorkflow && $loan->status === 'under_review')
        <form method="POST" action="{{ route('loans.submit-approval', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-brand-200 bg-brand-50 p-4 md:grid-cols-[1fr_auto]">
            @csrf
            <div>
                <div class="text-sm font-semibold text-slate-950">Approval queue</div>
                <div class="text-sm text-slate-500">Send the appraised application to the approver.</div>
            </div>
            <button class="df-action-primary">Submit for Approval</button>
        </form>
    @endif

    @if($canApproveLoan && in_array($loan->status, ['submitted', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification'], true))
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <form method="POST" action="{{ route('loans.approve', $loan) }}" class="grid gap-3 rounded-lg border border-forest-500/20 bg-forest-50 p-4">
                @csrf
                <label class="text-sm font-semibold text-slate-700">Approved amount <x-required-badge /><input name="approved_amount" type="number" min="100" step="100" value="{{ old('approved_amount', $loan->requested_amount) }}" required></label>
                <textarea name="comments" placeholder="Approval comments">{{ old('comments') }}</textarea>
                <button class="df-action-primary">Approve Loan</button>
            </form>
            <form method="POST" action="{{ route('loans.reject', $loan) }}" class="grid gap-3 rounded-lg border border-coral-500/20 bg-coral-50 p-4">
                @csrf
                <label class="text-sm font-semibold text-coral-800">Rejection reason <x-required-badge /><input name="reason" placeholder="Rejection reason" required></label>
                <button class="df-action-danger">Reject Loan</button>
            </form>
        </div>
    @endif

    @if($canDisburseLoan && $loan->status === 'approved')
        <form method="POST" action="{{ route('loans.disburse', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-brand-200 bg-brand-50 p-4 md:grid-cols-[240px_1fr_auto]">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Payment method <x-required-badge /><select name="payment_method" class="mt-1" required><option value="transfer_to_savings">Transfer to savings</option><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select></label>
            <div class="text-sm text-slate-600">Application fee is deducted from proceeds using the admin setting.</div>
            <button class="df-action-primary">Disburse</button>
        </form>
    @endif

    @if($canPostLoanRepayment && in_array($loan->status, ['active', 'in_arrears'], true))
        <form method="POST" action="{{ route('loans.repayments.store', $loan) }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-5">
            @csrf
            <label class="text-sm font-semibold text-slate-700">Amount <x-required-badge /><input name="amount" type="number" min="100" step="100" placeholder="Amount" required></label>
            <label class="text-sm font-semibold text-slate-700">Payment method <x-required-badge /><select name="payment_method" required><option value="cash">Cash</option><option value="mobile_money">Mobile money</option><option value="bank">Bank</option><option value="savings_deduction">Savings deduction</option><option value="cheque">Cheque</option></select></label>
            <input name="reference" placeholder="Reference">
            <label class="text-sm font-semibold text-slate-700">Paid date <x-required-badge /><input name="paid_at" type="date" value="{{ now()->toDateString() }}" required></label>
            <button class="df-action-primary">Post repayment</button>
        </form>
    @endif

    @unless($hasVisibleWorkflowAction)
        <p class="mt-4 text-sm text-slate-500">There are no workflow actions available for this loan at the current status.</p>
    @endunless
</section>
