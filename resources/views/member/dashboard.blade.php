@extends('layouts.app')

@php
    $money = fn ($amount): string => number_format((float) $amount, 2).' '.($client->organization?->currency ?: 'UGX');
    $nextInstallments = $progress['installments']->take(6);
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
        <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700">Client status · {{ str($client->status)->headline() }}</span>
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

@if(request('payment_return'))
    <div class="mb-5 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">You returned from Nylon Pay. Your balance updates after the signed payment confirmation is received.</div>
@endif

<nav class="mb-5 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" aria-label="My account navigation">
    <a class="df-action-primary" href="#repayments">Repayments</a>
    <a class="df-action-secondary" href="{{ route('member.statements') }}">My statements</a>
    <a class="df-action-secondary" href="#my-accounts">Savings & investments</a>
    <a class="df-action-secondary" href="#recent-activity">Recent activity</a>
    @if($canRequestLoan)<a class="df-action-secondary" href="#loan-request">Request a loan</a>@endif
</nav>

@include('member.partials.progress')

@unless($nylonPayConfigured)
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Online payments are unavailable until your organization configures its Nylon Pay collection account.</div>
@endunless

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

<div id="repayments" class="mt-6 grid scroll-mt-6 gap-5 2xl:grid-cols-[minmax(0,1.35fr)_minmax(0,0.65fr)]">
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
                    $unpaidSchedules = $loan->schedules->filter(fn ($schedule) => $schedule->status !== 'paid' && (float) $schedule->outstanding_amount > 0)->sortBy('due_date');
                    $dueNow = (float) $unpaidSchedules->filter(fn ($schedule) => $schedule->due_date->lte(today()))->sum('outstanding_amount');
                    $suggestedRepayment = $canRepay ? min($loanBalance, $dueNow ?: (float) ($unpaidSchedules->first()?->outstanding_amount ?? $loanBalance)) : 0;
                @endphp
                <div id="repay-loan-{{ $loan->id }}" class="min-w-0 scroll-mt-6 rounded-lg border border-slate-200">
                    <div class="grid gap-4 border-b border-slate-200 p-4 xl:grid-cols-2">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-slate-950">{{ $loan->loan_number }}</h3>
                                <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ str($loan->status)->headline() }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ $loan->product?->name ?? 'Loan product' }}</p>
                            <a class="mt-2 inline-block text-sm font-semibold text-brand-700" href="{{ route('member.statements', ['account' => 'loan:'.$loan->id]) }}">View loan statement →</a>
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
                                <label class="grid gap-1 text-xs font-semibold text-slate-600">Repayment amount<input name="amount" type="number" min="100" step="100" value="{{ $suggestedRepayment }}" @disabled(! $canRepay) class="w-full rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500"></label>
                                <label class="grid gap-1 text-xs font-semibold text-slate-600">Mobile money phone<input name="phone" value="{{ old('phone', $defaultPaymentPhone) }}" placeholder="Mobile money phone" @disabled(! $canRepay) class="w-full rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500"></label>
                                <p class="text-xs text-slate-500">Suggested amount covers due installments or your next installment. Full settlement: {{ $money($loanBalance) }}.</p>
                                <button @disabled(! $canRepay || ! $nylonPayConfigured) class="rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">Pay with Nylon Pay</button>
                            </div>
                        </form>
                    </div>

                    <details class="px-4 py-3">
                        <summary class="cursor-pointer py-2 text-sm font-semibold text-brand-700">View full repayment schedule · {{ $loan->schedules->count() }} installments</summary>
                    <div>
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
                                @foreach ($loan->schedules->sortBy('installment_number') as $schedule)
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
                    </details>
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

<div id="my-accounts" class="mt-6 grid scroll-mt-6 gap-5 xl:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="font-semibold text-slate-950">Savings Accounts</h2>
        <div class="mt-4 grid gap-3">
            @foreach($client->accounts->where('accountType.category', 'savings') as $basicAccount)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap justify-between gap-3"><div><h3 class="font-semibold text-slate-950">{{ $basicAccount->accountType?->name ?? 'Savings account' }}</h3><p class="text-sm text-slate-500">{{ $basicAccount->account_number }} · {{ str($basicAccount->status)->headline() }}</p></div><p class="font-semibold">{{ $money($basicAccount->balance) }}</p></div>
                    <a class="mt-3 inline-block text-sm font-semibold text-brand-700" href="{{ route('member.statements', ['account' => 'account:'.$basicAccount->id]) }}">View statement →</a>
                </div>
            @endforeach
            @forelse ($savingsAccounts as $account)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row">
                        <div>
                            <div class="font-semibold text-slate-950">{{ $account->product?->name ?? 'Savings account' }}</div>
                            <div class="text-sm text-slate-500">{{ $account->account_number }} · {{ str($account->status)->headline() }}</div>
                            <a class="mt-2 inline-block text-sm font-semibold text-brand-700" href="{{ route('member.statements', ['account' => 'savings:'.$account->id]) }}">View statement →</a>
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
                        <button @disabled($account->status !== 'active' || ! $nylonPayConfigured) class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-300">Deposit with Nylon Pay</button>
                    </form>
                </div>
            @empty
                @if($client->accounts->where('accountType.category', 'savings')->isEmpty())<div class="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">No savings accounts are linked to this client profile yet.</div>@endif
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
                            <a class="mt-2 inline-block text-sm font-semibold text-brand-700" href="{{ route('member.statements', ['account' => 'investment:'.$investment->id]) }}">View statement →</a>
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

<section id="recent-activity" class="mt-6 scroll-mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
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
                    <span class="text-xs font-semibold {{ $transaction->status === 'reversed' || $transaction->reversed_at ? 'text-rose-700' : 'text-slate-500' }}">{{ str($transaction->status ?? 'posted')->headline() }}</span>
                    <div class="text-slate-500">{{ $reference }} · {{ $transactionDate->format('d M Y') }}</div>
                </div>
                <div class="font-semibold {{ in_array($transactionType, ['interest_posting', 'return_posting'], true) ? 'text-emerald-700' : 'text-slate-800' }}">{{ $money($amount) }}</div>
            </div>
        @empty
            <p class="text-sm text-slate-500">No transactions posted yet.</p>
        @endforelse
    </div>
</section>
<details id="loan-request" class="mt-6 scroll-mt-6 rounded-lg border border-brand-200 bg-brand-50 p-5">
    <summary class="cursor-pointer font-semibold text-slate-950">Request a new loan</summary>
    <p class="mt-2 text-sm text-slate-600">Available to verified clients with no unpaid loan or application in progress.</p>
    @unless($canRequestLoan)<p class="mt-2 text-sm font-semibold text-amber-800">You are not currently eligible for another loan request.</p>@endunless
    <form method="POST" action="{{ route('member.loan-applications.store') }}" class="mt-4 grid gap-3 lg:grid-cols-3">
        @csrf
        <label class="grid gap-1 text-sm">Loan product<select name="loan_product_id" @disabled(! $canRequestLoan) required><option value="">Choose loan product</option>@foreach($loanProducts as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label>
        <label class="grid gap-1 text-sm">Amount<input name="requested_amount" type="number" min="100" step="100" @disabled(! $canRequestLoan) required></label>
        <label class="grid gap-1 text-sm">Repayment periods<input name="term_periods" type="number" min="1" @disabled(! $canRequestLoan) required></label>
        <label class="grid gap-1 text-sm">Frequency<select name="repayment_frequency" @disabled(! $canRequestLoan) required><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="daily">Daily</option></select></label>
        <label class="grid gap-1 text-sm">Purpose<input name="purpose" @disabled(! $canRequestLoan) required></label>
        <label class="grid gap-1 text-sm">Source of repayment<input name="source_of_repayment" @disabled(! $canRequestLoan)></label>
        <button @disabled(! $canRequestLoan) class="df-action-primary lg:col-span-3 disabled:bg-slate-300">Send request to Loan Officer</button>
    </form>
</details>
@endsection
