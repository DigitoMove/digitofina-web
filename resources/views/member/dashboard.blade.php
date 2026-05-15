@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-col justify-between gap-3 lg:flex-row lg:items-end">
    <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Member self-service</p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-950">My Savings & Investment Growth</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $client->full_name }} · Member No. {{ $client->client_number }}</p>
    </div>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
        Interest growth is posted automatically by product cycle.
    </div>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Savings / investments</div>
        <div class="mt-2 text-2xl font-semibold">{{ number_format($savingsTotal, 2) }} UGX</div>
    </div>
    <div class="rounded-xl border border-emerald-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Interest earned</div>
        <div class="mt-2 text-2xl font-semibold text-emerald-700">{{ number_format($investmentGrowth, 2) }} UGX</div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Outstanding loan balance</div>
        <div class="mt-2 text-2xl font-semibold">{{ number_format($loanBalance, 2) }} UGX</div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">KYC status</div>
        <div class="mt-2 text-2xl font-semibold">{{ str($client->kyc_status)->headline() }}</div>
    </div>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-950">My Savings / Investment Accounts</h2>
                <p class="mt-1 text-sm text-slate-500">Balances grow when interest is posted daily, weekly, monthly, quarterly, or annually.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-3">
            @forelse ($savingsAccounts as $account)
                @php
                    $interestEarned = (float) $account->transactions->where('type', 'interest_posting')->where('status', 'successful')->sum('amount');
                    $frequency = $account->product?->interest_posting_frequency ?? 'monthly';
                    $rate = (float) ($account->product?->interest_rate ?? 0);
                @endphp
                <div class="rounded-xl border border-slate-200 p-4 text-sm transition hover:border-brand-200 hover:bg-brand-50/40">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $account->product?->name ?? 'Savings Investment' }}</div>
                            <div class="mt-1 text-slate-500">{{ $account->account_number }} · {{ str($account->status)->headline() }}</div>
                        </div>
                        <div class="text-left sm:text-right">
                            <div class="text-lg font-semibold text-slate-950">{{ number_format($account->current_balance, 2) }} UGX</div>
                            <div class="text-xs font-semibold text-emerald-700">+{{ number_format($interestEarned, 2) }} UGX earned</div>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="text-xs uppercase tracking-wide text-slate-400">Growth cycle</div>
                            <div class="mt-1 font-semibold text-slate-700">{{ str($frequency)->headline() }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="text-xs uppercase tracking-wide text-slate-400">Interest rate</div>
                            <div class="mt-1 font-semibold text-slate-700">{{ number_format($rate, 2) }}%</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="text-xs uppercase tracking-wide text-slate-400">Available balance</div>
                            <div class="mt-1 font-semibold text-slate-700">{{ number_format($account->available_balance, 2) }} UGX</div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 p-6 text-sm text-slate-500">No savings investment accounts are linked to this member profile yet.</div>
            @endforelse
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Recent Growth & Transactions</h2>
        <div class="mt-4 grid gap-3">
            @forelse ($transactions as $transaction)
                @php
                    $transactionType = is_string($transaction->type ?? null) ? $transaction->type : ($transaction->type?->name ?? 'transaction');
                    $transactionDate = $transaction->transaction_date ?? $transaction->posted_at ?? $transaction->created_at;
                @endphp
                <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 text-sm">
                    <div>
                        <div class="font-semibold text-slate-900">{{ str($transactionType)->headline() }}</div>
                        <div class="text-slate-500">{{ $transaction->reference }} · {{ $transactionDate->format('d M Y') }}</div>
                    </div>
                    <div class="font-semibold {{ $transactionType === 'interest_posting' ? 'text-emerald-700' : 'text-slate-800' }}">{{ number_format($transaction->amount, 2) }} UGX</div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No transactions posted yet.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold text-slate-950">Loan Repayment Progress</h2>
    <div class="mt-4 grid gap-5">
        @forelse ($client->loans as $loan)
            <div class="rounded-xl border border-slate-200">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                    <div>
                        <div class="font-semibold text-slate-950">{{ $loan->loan_number }} · {{ $loan->product->name }}</div>
                        <div class="text-sm text-slate-500">{{ str($loan->status)->headline() }} · Principal balance {{ number_format($loan->principal_balance, 2) }} UGX</div>
                    </div>
                    <div class="text-sm font-semibold">{{ number_format($loan->approved_amount ?? $loan->requested_amount, 2) }} UGX</div>
                </div>
                <div class="overflow-x-auto px-4 py-3">
                    <table class="min-w-full border-separate border-spacing-x-3 border-spacing-y-2 text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-3 py-2">Installment</th><th class="px-3 py-2">Due date</th><th class="px-3 py-2">Total due</th><th class="px-3 py-2">Paid</th><th class="px-3 py-2">Outstanding</th><th class="px-3 py-2">Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($loan->schedules->take(8) as $schedule)
                                <tr class="bg-slate-50 transition hover:bg-brand-50/70">
                                    <td class="px-3 py-3">{{ $schedule->installment_number }}</td>
                                    <td class="px-3 py-3">{{ $schedule->due_date->format('d M Y') }}</td>
                                    <td class="px-3 py-3">{{ number_format($schedule->total_due, 2) }}</td>
                                    <td class="px-3 py-3">{{ number_format($schedule->amount_paid, 2) }}</td>
                                    <td class="px-3 py-3">{{ number_format($schedule->outstanding_amount, 2) }}</td>
                                    <td class="px-3 py-3">{{ str($schedule->status)->headline() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-500">No loans found on your member profile.</p>
        @endforelse
    </div>
</section>
@endsection
