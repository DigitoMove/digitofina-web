@props([
    'transaction',
    'closeUrl',
])

@if($transaction)
    @php
        $journalAccount = $transaction->journalEntries->first()?->chartAccount;
    @endphp
    <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4 print:static print:bg-transparent print:p-0">
        <section class="w-full max-w-lg overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl print:max-w-none print:border-0 print:shadow-none">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 print:hidden">
                <div>
                    <h2 class="text-base font-semibold text-slate-950">Transaction Receipt</h2>
                    <p class="text-sm text-slate-500">Posted successfully. Close to continue posting.</p>
                </div>
                <a href="{{ $closeUrl }}" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Close receipt">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </a>
            </div>

            <div class="p-6">
                <div class="text-center">
                    <div class="text-xl font-bold text-slate-950">DigitoFina</div>
                    <div class="text-sm text-slate-500">Transaction Receipt</div>
                </div>

                <dl class="mt-6 grid gap-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Reference</dt><dd class="font-semibold text-slate-950">{{ $transaction->reference }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Type</dt><dd>{{ $transaction->type?->name }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Client</dt><dd class="text-right">{{ $transaction->account?->client?->full_name ?? $transaction->account?->client?->business_name ?? 'General ledger posting' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Account</dt><dd>{{ $transaction->account?->account_number ?? ($journalAccount ? $journalAccount->code.' · '.$journalAccount->name : 'Drawer ledger') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Amount</dt><dd class="font-semibold text-slate-950">{{ number_format((float) $transaction->amount, 2) }} {{ $transaction->currency }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Balance after</dt><dd>{{ number_format((float) $transaction->balance_after, 2) }} {{ $transaction->currency }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Posted by</dt><dd>{{ $transaction->user?->name }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Posted at</dt><dd>{{ $transaction->posted_at?->format('d M Y H:i') }}</dd></div>
                </dl>
            </div>

            <div class="grid gap-2 border-t border-slate-200 bg-slate-50 p-4 sm:grid-cols-3 print:hidden">
                <button type="button" onclick="window.print()" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Print</button>
                <a href="{{ route('receipts.show', $transaction) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-100">Details</a>
                <a href="{{ $closeUrl }}" class="rounded-lg bg-brand-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-brand-800">Close</a>
            </div>
        </section>
    </div>
@endif
