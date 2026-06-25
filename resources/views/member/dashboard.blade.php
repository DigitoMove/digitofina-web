@extends('layouts.app')

@php
    $money = fn ($amount): string => number_format((float) $amount, 2).' UGX';
    $nextInstallments = $loans
        ->flatMap(fn ($loan) => $loan->schedules->where('status', '!=', 'paid')->map(fn ($schedule) => ['loan' => $loan, 'schedule' => $schedule]))
        ->sortBy(fn ($item) => $item['schedule']->due_date?->toDateString())
        ->take(6);
@endphp

@section('content')
<div class="mb-6 flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
    <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Client self-service</p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-950">My Financial Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $client->full_name }} · Member No. {{ $client->client_number }}</p>
    </div>
    <div class="flex flex-wrap gap-2 text-sm">
        <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700">KYC {{ str($client->kyc_status)->headline() }}</span>
        <span class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 font-semibold text-emerald-800">{{ $client->kyc_completion_percentage }}% complete</span>
    </div>
</div>

@if (session('status'))
    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
        {{ session('status') }}
        @if (session('payment_reference'))
            <span class="ml-2 font-semibold">Ref: {{ session('payment_reference') }}</span>
        @endif
    </div>
@endif

@if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        {{ $errors->first() }}
    </div>
@endif

<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Savings balance</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $money($savingsTotal) }}</div>
    </div>
    <div class="rounded-lg border border-sky-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Investment balance</div>
        <div class="mt-2 text-2xl font-semibold text-sky-800">{{ $money($investmentTotal) }}</div>
    </div>
    <div class="rounded-lg border border-amber-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Loan balance</div>
        <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $money($loanBalance) }}</div>
    </div>
    <div class="rounded-lg border border-rose-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Loan penalties</div>
        <div class="mt-2 text-2xl font-semibold text-rose-700">{{ $money($loanBreakdown['penalties']) }}</div>
    </div>
    <div class="rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-medium text-slate-500">Returns / interest</div>
        <div class="mt-2 text-2xl font-semibold text-emerald-700">{{ $money($investmentGrowth) }}</div>
    </div>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(340px,0.65fr)]">
    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
            <div>
                <h2 class="font-semibold text-slate-950">Loans & Online Repayments</h2>
                <p class="mt-1 text-sm text-slate-500">Full settlement includes penalties, fees, interest, and principal.</p>
            </div>
            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <div class="text-xs uppercase tracking-wide text-slate-400">Principal</div>
                    <div class="font-semibold text-slate-800">{{ $money($loanBreakdown['principal']) }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <div class="text-xs uppercase tracking-wide text-slate-400">Interest</div>
                    <div class="font-semibold text-slate-800">{{ $money($loanBreakdown['interest']) }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <div class="text-xs uppercase tracking-wide text-slate-400">Fees</div>
                    <div class="font-semibold text-slate-800">{{ $money($loanBreakdown['fees']) }}</div>
                </div>
                <div class="rounded-lg bg-rose-50 px-3 py-2">
                    <div class="text-xs uppercase tracking-wide text-rose-500">Penalties</div>
                    <div class="font-semibold text-rose-700">{{ $money($loanBreakdown['penalties']) }}</div>
                </div>
            </div>
        </div>

        <div class="mt-5 grid gap-4">
            @forelse ($loans as $loan)
                @php
                    $loanBalance = (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance;
                    $canRepay = in_array($loan->status, \App\Models\Loan::OPERATIONAL_STATUSES, true) && $loanBalance > 0;
                @endphp
                <div class="rounded-lg border border-slate-200">
                    <div class="grid gap-4 border-b border-slate-200 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.55fr)]">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-slate-950">{{ $loan->loan_number }}</h3>
                                <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ str($loan->status)->headline() }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ $loan->product?->name ?? 'Loan product' }}</p>
                            <div class="mt-3 grid gap-2 text-sm sm:grid-cols-4">
                                <div><span class="block text-slate-400">Principal</span><span class="font-semibold">{{ $money($loan->principal_balance) }}</span></div>
                                <div><span class="block text-slate-400">Interest</span><span class="font-semibold">{{ $money($loan->interest_balance) }}</span></div>
                                <div><span class="block text-slate-400">Fees</span><span class="font-semibold">{{ $money($loan->fees_balance) }}</span></div>
                                <div><span class="block text-rose-500">Penalties</span><span class="font-semibold text-rose-700">{{ $money($loan->penalty_balance) }}</span></div>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('member.loans.repayments.store', $loan) }}" class="rounded-lg bg-slate-50 p-3">
                            @csrf
                            <div class="text-sm font-semibold text-slate-800">Repay online</div>
                            <div class="mt-3 grid gap-2">
                                <input name="amount" type="number" min="0" step="1" value="{{ $canRepay ? $loanBalance : 0 }}" @disabled(! $canRepay) class="w-full rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                <input name="phone" value="{{ old('phone', $defaultPaymentPhone) }}" placeholder="Mobile money phone" @disabled(! $canRepay) class="w-full rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                @if ($paymentProviders->count() > 1)
                                    <select name="provider_id" @disabled(! $canRepay) class="w-full rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                        @foreach ($paymentProviders as $provider)
                                            <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button @disabled(! $canRepay) class="rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">Pay {{ $money($loanBalance) }}</button>
                            </div>
                        </form>
                    </div>

                    <div class="overflow-x-auto px-4 py-3">
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Installment</th>
                                    <th class="px-3 py-2">Due date</th>
                                    <th class="px-3 py-2">Penalty</th>
                                    <th class="px-3 py-2">Total due</th>
                                    <th class="px-3 py-2">Paid</th>
                                    <th class="px-3 py-2">Outstanding</th>
                                    <th class="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($loan->schedules->take(8) as $schedule)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-3 py-3">{{ $schedule->installment_number }}</td>
                                        <td class="px-3 py-3">{{ $schedule->due_date->format('d M Y') }}</td>
                                        <td class="px-3 py-3 text-rose-700">{{ $money((float) $schedule->penalty_due - (float) $schedule->penalty_paid) }}</td>
                                        <td class="px-3 py-3">{{ $money($schedule->total_due) }}</td>
                                        <td class="px-3 py-3">{{ $money($schedule->amount_paid) }}</td>
                                        <td class="px-3 py-3 font-semibold">{{ $money($schedule->outstanding_amount) }}</td>
                                        <td class="px-3 py-3">{{ str($schedule->status)->headline() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">No loans found on your client profile.</div>
            @endforelse
        </div>
    </section>

    <aside class="grid gap-5">
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold text-slate-950">Next Repayments</h2>
            <div class="mt-4 grid gap-3">
                @forelse ($nextInstallments as $item)
                    <div class="rounded-lg border border-slate-200 p-3 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $item['loan']->loan_number }}</div>
                                <div class="text-slate-500">{{ $item['schedule']->due_date->format('d M Y') }}</div>
                            </div>
                            <div class="text-right font-semibold">{{ $money($item['schedule']->outstanding_amount) }}</div>
                        </div>
                        @if ((float) $item['schedule']->penalty_due > (float) $item['schedule']->penalty_paid)
                            <div class="mt-2 text-xs font-semibold text-rose-700">Penalty outstanding: {{ $money((float) $item['schedule']->penalty_due - (float) $item['schedule']->penalty_paid) }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No upcoming installments.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold text-slate-950">Online Payment Status</h2>
            <div class="mt-4 grid gap-3">
                @forelse ($paymentRequests as $payment)
                    <div class="rounded-lg border border-slate-200 p-3 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-900">{{ $payment->reference }}</div>
                                <div class="text-slate-500">{{ $payment->created_at->format('d M Y H:i') }} · {{ $payment->provider?->name }}</div>
                            </div>
                            <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ str($payment->status)->headline() }}</span>
                        </div>
                        <div class="mt-2 font-semibold">{{ $money($payment->amount) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No online payment requests yet.</p>
                @endforelse
            </div>
        </section>
    </aside>
</div>

<div class="mt-6 grid gap-5 xl:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Savings Accounts</h2>
        <div class="mt-4 grid gap-3">
            @forelse ($savingsAccounts as $account)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $account->product?->name ?? 'Savings account' }}</div>
                            <div class="text-sm text-slate-500">{{ $account->account_number }} · {{ str($account->status)->headline() }}</div>
                        </div>
                        <div class="text-left sm:text-right">
                            <div class="text-lg font-semibold">{{ $money($account->current_balance) }}</div>
                            <div class="text-xs text-slate-500">Available {{ $money($account->available_balance) }}</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('member.savings.deposits.store', $account) }}" class="mt-4 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                        @csrf
                        <input name="amount" type="number" min="0" step="1" placeholder="Amount" @disabled($account->status !== 'active') class="rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <input name="phone" value="{{ old('phone', $defaultPaymentPhone) }}" placeholder="Mobile money phone" @disabled($account->status !== 'active') class="rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <button @disabled($account->status !== 'active') class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-300">Deposit</button>
                    </form>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">No savings accounts are linked to this client profile yet.</div>
            @endforelse
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Investments</h2>
        <div class="mt-4 grid gap-3">
            @forelse ($investmentAccounts as $investment)
                @php
                    $canTopUp = $investment->status === 'active' && (bool) $investment->plan?->allow_top_ups;
                @endphp
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $investment->plan?->name ?? 'Investment account' }}</div>
                            <div class="text-sm text-slate-500">{{ $investment->investment_number }} · {{ str($investment->status)->headline() }}</div>
                        </div>
                        <div class="text-left sm:text-right">
                            <div class="text-lg font-semibold">{{ $money($investment->current_balance) }}</div>
                            <div class="text-xs text-emerald-700">Expected return {{ $money($investment->expected_return_amount) }}</div>
                        </div>
                    </div>
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-sky-600" style="width: {{ $investment->progressPercentage() }}%"></div>
                    </div>
                    <form method="POST" action="{{ route('member.investments.top-ups.store', $investment) }}" class="mt-4 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                        @csrf
                        <input name="amount" type="number" min="0" step="1" placeholder="Amount" @disabled(! $canTopUp) class="rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <input name="phone" value="{{ old('phone', $defaultPaymentPhone) }}" placeholder="Mobile money phone" @disabled(! $canTopUp) class="rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        <button @disabled(! $canTopUp) class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-300">Top up</button>
                    </form>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">No investment accounts are linked to this client profile yet.</div>
            @endforelse
        </div>
    </section>
</div>

<section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="font-semibold text-slate-950">Recent Activity</h2>
    <div class="mt-4 grid gap-3">
        @forelse ($transactions as $transaction)
            @php
                $transactionType = is_string($transaction->type ?? null) ? $transaction->type : ($transaction->type?->name ?? 'loan_repayment');
                $transactionDate = $transaction->transaction_date ?? $transaction->paid_at ?? $transaction->posted_at ?? $transaction->created_at;
                $reference = $transaction->reference ?? $transaction->transaction_reference ?? 'Posted transaction';
                $amount = $transaction->amount ?? $transaction->total_paid ?? 0;
            @endphp
            <div class="flex flex-col justify-between gap-2 rounded-lg border border-slate-200 p-3 text-sm sm:flex-row sm:items-center">
                <div>
                    <div class="font-semibold text-slate-900">{{ str($transactionType)->headline() }}</div>
                    <div class="text-slate-500">{{ $reference }} · {{ $transactionDate->format('d M Y') }}</div>
                </div>
                <div class="font-semibold {{ in_array($transactionType, ['interest_posting', 'return_posting'], true) ? 'text-emerald-700' : 'text-slate-800' }}">{{ $money($amount) }}</div>
            </div>
        @empty
            <p class="text-sm text-slate-500">No transactions posted yet.</p>
        @endforelse
    </div>
</section>
@endsection
