@extends('layouts.app')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-slate-950">My Progress</h1>
    <p class="text-sm text-slate-500">{{ $client->full_name }} · Member No. {{ $client->client_number }}</p>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-3">
    <div class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Savings balance</div>
        <div class="mt-2 text-2xl font-semibold">{{ number_format($savingsTotal, 2) }} UGX</div>
    </div>
    <div class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Outstanding loan balance</div>
        <div class="mt-2 text-2xl font-semibold">{{ number_format($loanBalance, 2) }} UGX</div>
    </div>
    <div class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-sm font-medium text-slate-500">KYC status</div>
        <div class="mt-2 text-2xl font-semibold">{{ str($client->kyc_status)->headline() }}</div>
    </div>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-2">
    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold">My Accounts</h2>
        <div class="mt-4 grid gap-3">
            @foreach ($client->accounts as $account)
                <div class="rounded border border-slate-200 p-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-semibold">{{ $account->account_name }}</span>
                        <span>{{ number_format($account->balance, 2) }} UGX</span>
                    </div>
                    <div class="mt-1 text-slate-500">{{ $account->account_number }} · {{ $account->accountType->name }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold">Recent Transactions</h2>
        <div class="mt-4 grid gap-3">
            @forelse ($transactions as $transaction)
                <div class="flex items-center justify-between rounded border border-slate-200 p-3 text-sm">
                    <div>
                        <div class="font-semibold">{{ $transaction->type->name }}</div>
                        <div class="text-slate-500">{{ $transaction->reference }} · {{ $transaction->posted_at->format('d M Y') }}</div>
                    </div>
                    <div>{{ number_format($transaction->amount, 2) }} UGX</div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No transactions posted yet.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="mt-6 rounded border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold">Loan Repayment Progress</h2>
    <div class="mt-4 grid gap-5">
        @forelse ($client->loans as $loan)
            <div class="rounded border border-slate-200">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                    <div>
                        <div class="font-semibold">{{ $loan->loan_number }} · {{ $loan->product->name }}</div>
                        <div class="text-sm text-slate-500">{{ str($loan->status)->headline() }} · Principal balance {{ number_format($loan->principal_balance, 2) }} UGX</div>
                    </div>
                    <div class="text-sm font-semibold">{{ number_format($loan->approved_amount ?? $loan->requested_amount, 2) }} UGX</div>
                </div>
                <div class="overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr><th class="p-3">Installment</th><th>Due date</th><th>Total due</th><th>Paid</th><th>Outstanding</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($loan->schedules->take(8) as $schedule)
                                <tr class="border-t border-slate-200">
                                    <td class="p-3">{{ $schedule->installment_number }}</td>
                                    <td>{{ $schedule->due_date->format('d M Y') }}</td>
                                    <td>{{ number_format($schedule->total_due, 2) }}</td>
                                    <td>{{ number_format($schedule->amount_paid, 2) }}</td>
                                    <td>{{ number_format($schedule->outstanding_amount, 2) }}</td>
                                    <td>{{ str($schedule->status)->headline() }}</td>
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
