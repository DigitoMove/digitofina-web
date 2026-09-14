<section class="df-panel p-5">
    <h2 class="font-semibold text-slate-950">Application fees</h2>
    <p class="mt-2 text-sm text-slate-600">Fee setting: {{ $loan->application_fee_override === null ? 'Normal fee calculation' : 'UGX '.number_format((float) $loan->application_fee_override, 2).((float) $loan->application_fee_override === 0.0 ? ' (waived)' : ' (custom)') }}</p>
    @if($loan->application_fee_override_reason)
        <p class="mt-1 text-sm text-slate-500">Reason: {{ $loan->application_fee_override_reason }}</p>
    @endif
    @foreach(['application_fee_override', 'application_fee_override_reason', 'application_fee_refund', 'amount', 'reason'] as $feeError)
        @error($feeError)<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
    @endforeach
    @if(auth()->user()->canPerform('disburse-loans') && in_array($loan->status, ['draft', 'under_review', 'submitted', 'recommended', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved'], true) && $loan->disbursements->isEmpty())
        <form method="POST" action="{{ route('loans.application-fee.update', $loan) }}" class="mt-4 grid gap-3 md:grid-cols-2">
            @csrf @method('PUT')
            <p class="text-sm text-slate-500 md:col-span-2">Save any special fee before disbursing. Leave blank for the normal fee, or enter 0 to waive it.</p>
            <label class="text-sm font-medium">Application fee (UGX)<input name="application_fee_override" type="number" min="0" step="100" value="{{ old('application_fee_override', $loan->application_fee_override) }}"></label>
            <label class="text-sm font-medium">Reason for change<input name="application_fee_override_reason" maxlength="1000" required value="{{ old('application_fee_override_reason') }}"></label>
            <button class="df-action-secondary md:col-span-2">Save application fee</button>
        </form>
    @endif
    @foreach($loan->disbursements as $feeDisbursement)
        @php
            $feeRefunds = $feeDisbursement->applicationFeeRefunds;
            $feeRefunded = (float) $feeRefunds->where('status', 'posted')->whereNull('reversal_of_id')->whereNull('reversed_by')->sum('amount');
            $feeRemaining = max(0, (float) $feeDisbursement->fees_deducted - $feeRefunded);
        @endphp
        <div class="mt-4 rounded border border-slate-200 p-4">
            <p class="text-sm font-semibold">Disbursement {{ $feeDisbursement->transaction_reference }}</p>
            <p class="mt-1 text-sm">Charged: UGX {{ number_format((float) $feeDisbursement->fees_deducted, 2) }} · Refunded: UGX {{ number_format($feeRefunded, 2) }} · Net fee: UGX {{ number_format($feeRemaining, 2) }}</p>
            @foreach($feeRefunds as $feeRefund)
                <p class="mt-2 text-sm text-slate-600">{{ $feeRefund->posted_at?->format('d M Y H:i') }} · {{ $feeRefund->reference }} · UGX {{ number_format((float) $feeRefund->amount, 2) }} · {{ $feeRefund->user?->name }} · {{ $feeRefund->status }} · {{ $feeRefund->description }}</p>
            @endforeach
            @if(auth()->user()->canPerform('disburse-loans') && $feeDisbursement->status === 'successful' && $feeRemaining > 0)
                <form method="POST" action="{{ route('loans.application-fee.refund', [$loan, $feeDisbursement->id]) }}" class="mt-3 grid gap-3 md:grid-cols-2">
                    @csrf
                    <label class="text-sm font-medium">Refund amount (UGX)<input name="amount" type="number" min="100" max="{{ $feeRemaining }}" step="100" required></label>
                    <label class="text-sm font-medium">Refund reason<input name="reason" maxlength="1000" required></label>
                    <p class="text-sm text-slate-500 md:col-span-2">Refund all or part of the remaining fee to the original client savings account. The original charge stays on record.</p>
                    <button class="df-action-secondary md:col-span-2">Refund application fee to savings</button>
                </form>
            @endif
        </div>
    @endforeach
</section>
