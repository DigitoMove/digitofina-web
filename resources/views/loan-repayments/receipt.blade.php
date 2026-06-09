@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-2xl rounded border bg-white p-6">
    <div class="flex items-start justify-between gap-4 border-b pb-4">
        <div><h1 class="text-xl font-semibold">Loan Repayment Receipt</h1><p class="text-sm text-slate-500">{{ $repayment->reference }}</p></div>
        <a href="{{ route('loans.show', $repayment->loan) }}" class="rounded border px-4 py-2 text-sm font-semibold print:hidden">Back to loan</a>
    </div>
    <dl class="mt-5 grid gap-4 text-sm md:grid-cols-2">
        <div><dt class="text-slate-500">Client</dt><dd class="font-semibold">{{ $repayment->loan->client->full_name }}</dd></div>
        <div><dt class="text-slate-500">Loan number</dt><dd>{{ $repayment->loan->loan_number }}</dd></div>
        <div><dt class="text-slate-500">Amount paid</dt><dd class="font-semibold">UGX {{ number_format($repayment->amount, 2) }}</dd></div>
        <div><dt class="text-slate-500">Payment method</dt><dd>{{ str($repayment->payment_method)->headline() }}</dd></div>
        <div><dt class="text-slate-500">Principal</dt><dd>UGX {{ number_format($repayment->principal_paid, 2) }}</dd></div>
        <div><dt class="text-slate-500">Interest</dt><dd>UGX {{ number_format($repayment->interest_paid, 2) }}</dd></div>
        <div><dt class="text-slate-500">Fees</dt><dd>UGX {{ number_format($repayment->fees_paid, 2) }}</dd></div>
        <div><dt class="text-slate-500">Penalty</dt><dd>UGX {{ number_format($repayment->penalty_paid, 2) }}</dd></div>
        <div><dt class="text-slate-500">Received by</dt><dd>{{ optional($repayment->receivedBy)->name }}</dd></div>
        <div><dt class="text-slate-500">Date/time</dt><dd>{{ $repayment->paid_at->format('d M Y') }} {{ $repayment->created_at->format('H:i:s') }}</dd></div>
    </dl>
    <div class="mt-8 border-t pt-6 text-sm text-slate-500">System generated receipt. Signature: ______________________________</div>
</div>
@endsection
