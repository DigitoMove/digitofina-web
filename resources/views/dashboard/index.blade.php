@extends('layouts.app')

@section('content')
<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-slate-950">Dashboard</h1>
        <p class="text-sm text-slate-500">Branch operations, cash position, approvals, and portfolio pulse.</p>
    </div>
    @if(in_array('teller', $enabledModules ?? [], true) && (auth()->user()->canPerform('manage-drawers') || auth()->user()->canPerform('post-transactions')))
        <a href="{{ route('teller.index') }}" class="df-action-primary">Open Teller Drawer</a>
    @endif
</div>

@php
    $moduleEnabled = fn (string $slug): bool => in_array($slug, $enabledModules ?? [], true);
    $dashboardUser = auth()->user();
    $isCashierDashboard = $dashboardUser?->isTellerCashier();
    $isLoanOfficerDashboard = $dashboardUser?->hasRole('loan-officer');
    $isCompactDashboard = $isCashierDashboard || $isLoanOfficerDashboard;
    $cards = [
        ['Total clients', $totalClients, 'bg-brand-50 text-brand-800', 'from-brand-500 to-brand-600', [38, 58, 44, 68, 74], route('clients.index')],
        ['Active accounts', $activeAccounts, 'bg-emerald-50 text-emerald-700', 'from-emerald-500 to-emerald-600', [46, 52, 63, 57, 78], route('accounts.index')],
        ['Loan applications', $loanApplications, 'bg-sky-50 text-sky-700', 'from-sky-500 to-blue-600', [30, 45, 62, 58, 75], route('loans.index', ['status_group' => 'applications'])],
        ['Loans in arrears', $arrearsCount, 'bg-red-50 text-red-700', 'from-red-500 to-rose-600', [20, 34, 48, 42, 60], route('arrears.index')],
        ['Due today', $dueTodayCount, 'bg-violet-50 text-violet-700', 'from-violet-500 to-indigo-600', [24, 38, 50, 70, 64], route('due-repayments.index')],
        ['Total savings', number_format($totalSavings, 2).' UGX', 'bg-brand-50 text-brand-800', 'from-brand-500 to-forest-500', [42, 55, 61, 76, 84], route('accounts.index')],
        ['Savings balance', number_format($savingsLedgerBalance, 2).' UGX', 'bg-teal-50 text-teal-700', 'from-teal-500 to-cyan-500', [40, 50, 58, 70, 86], route('savings-accounts.index')],
        ['Savings growth', number_format($savingsGrowthThisMonth, 2).' UGX', 'bg-emerald-50 text-emerald-700', 'from-emerald-500 to-green-500', [26, 39, 44, 60, 79], route('reports.index')],
        ['Active savers', $activeSavers, 'bg-cyan-50 text-cyan-700', 'from-cyan-500 to-sky-500', [34, 48, 58, 63, 75], route('savings-accounts.index')],
        ['Target progress', ($targetSavingsAmount > 0 ? number_format(($targetSavingsProgress / $targetSavingsAmount) * 100, 1) : '0.0').'%', 'bg-indigo-50 text-indigo-700', 'from-indigo-500 to-blue-500', [18, 30, 44, 58, 70], route('reports.index')],
        ['Fixed maturing soon', $fixedSavingsMaturingSoon, 'bg-amber-50 text-amber-700', 'from-amber-400 to-orange-500', [22, 34, 48, 62, 80], route('reports.index')],
        ['Dormant savings', $dormantSavingsAccounts, 'bg-slate-100 text-slate-700', 'from-slate-500 to-slate-700', [16, 28, 35, 46, 55], route('savings-accounts.index', ['status' => 'dormant'])],
        ['Total loans', number_format($totalLoans, 2).' UGX', 'bg-brand-50 text-brand-800', 'from-brand-600 to-sun-400', [35, 48, 65, 62, 80], route('loans.index')],
        ["Today's deposits", number_format($todayDeposits, 2).' UGX', 'bg-green-50 text-green-700', 'from-green-500 to-emerald-500', [28, 44, 50, 72, 88], route('reports.index')],
        ["Today's withdrawals", number_format($todayWithdrawals, 2).' UGX', 'bg-amber-50 text-amber-700', 'from-amber-400 to-orange-500', [24, 40, 38, 52, 46], route('reports.index')],
        ['Teller drawer balance', number_format($drawerBalance, 2).' UGX', 'bg-sun-50 text-sun-600', 'from-sun-400 to-brand-600', [34, 47, 57, 51, 69], route('teller.index')],
        ['Pending approvals', $pendingApprovals, 'bg-rose-50 text-rose-700', 'from-rose-500 to-pink-500', [18, 26, 44, 32, 54], route('reports.index')],
        ['Branches', $branchCount, 'bg-slate-100 text-slate-700', 'from-slate-500 to-brand-600', [44, 50, 54, 58, 62], route('branches.index')],
        ['Active staff', $staffCount, 'bg-lime-50 text-lime-700', 'from-lime-500 to-emerald-500', [32, 43, 52, 68, 73], route('users.index')],
        ['Audit events today', $auditEventsToday, 'bg-slate-100 text-slate-700', 'from-slate-600 to-slate-800', [20, 42, 30, 56, 48], route('audit-logs.index')],
        ['Total investors', $totalInvestors, 'bg-cyan-50 text-cyan-700', 'from-cyan-500 to-teal-500', [35, 48, 52, 70, 82], route('reports.index')],
        ['Active investments', $totalActiveInvestments, 'bg-teal-50 text-teal-700', 'from-teal-500 to-emerald-500', [30, 42, 55, 63, 76], route('reports.index')],
        ['Investment balance', number_format($totalInvestmentBalance, 2).' UGX', 'bg-indigo-50 text-indigo-700', 'from-indigo-500 to-sky-500', [40, 46, 66, 72, 88], route('reports.index')],
        ['Returns posted', number_format($totalReturnsPosted, 2).' UGX', 'bg-lime-50 text-lime-700', 'from-lime-500 to-green-500', [26, 45, 50, 62, 78], route('reports.index')],
        ['Maturing this month', $investmentsMaturingThisMonth, 'bg-amber-50 text-amber-700', 'from-amber-400 to-orange-500', [28, 36, 48, 60, 74], route('reports.index')],
        ['Investment withdrawals', number_format($investmentWithdrawalsThisMonth, 2).' UGX', 'bg-rose-50 text-rose-700', 'from-rose-500 to-red-500', [18, 34, 46, 42, 58], route('reports.index')],
    ];
    $cardModules = [
        'Total clients' => 'clients',
        'Active accounts' => ['savings', 'loans', 'investments', 'shares', 'fixed-deposits'],
        'Loan applications' => 'loans',
        'Loans in arrears' => 'loans',
        'Due today' => 'loans',
        'Total savings' => 'savings',
        'Savings balance' => 'savings',
        'Savings growth' => 'savings',
        'Active savers' => 'savings',
        'Target progress' => 'savings',
        'Fixed maturing soon' => 'fixed-deposits',
        'Dormant savings' => 'savings',
        'Total loans' => 'loans',
        "Today's deposits" => 'teller',
        "Today's withdrawals" => 'teller',
        'Teller drawer balance' => 'teller',
        'Pending approvals' => ['loans', 'savings', 'investments', 'accounting'],
        'Branches' => 'users',
        'Active staff' => 'users',
        'Audit events today' => 'audit-logs',
        'Total investors' => 'investments',
        'Active investments' => 'investments',
        'Investment balance' => 'investments',
        'Returns posted' => 'investments',
        'Maturing this month' => 'investments',
        'Investment withdrawals' => 'investments',
    ];
    $cardIsVisible = function (array $card) use ($cardModules, $moduleEnabled): bool {
        $modules = $cardModules[$card[0]] ?? [];
        $modules = is_array($modules) ? $modules : [$modules];

        return $modules !== [] && collect($modules)->contains(fn (string $module): bool => $moduleEnabled($module));
    };
    $cards = array_values(array_filter($cards, $cardIsVisible));
    $essentialCardLabels = match (true) {
        $isCashierDashboard => ['Due today', "Today's deposits", "Today's withdrawals", 'Teller drawer balance'],
        $isLoanOfficerDashboard => ['Loan applications', 'Loans in arrears', 'Due today', 'Total loans'],
        default => null,
    };
    $cards = $essentialCardLabels
        ? array_values(array_filter($cards, fn (array $card): bool => in_array($card[0], $essentialCardLabels, true)))
        : $cards;

    $cashRows = [
        ["Today's deposits", $todayDeposits, 'bg-emerald-500'],
        ["Today's withdrawals", $todayWithdrawals, 'bg-amber-500'],
        ['Drawer balance', $drawerBalance, 'bg-sun-400'],
    ];
    $cashMax = max(1, $todayDeposits, $todayWithdrawals, $drawerBalance);

    $portfolioRows = [
        ['Savings', $totalSavings, 'bg-teal-500', 'savings'],
        ['Tracked savings', $savingsLedgerBalance, 'bg-cyan-600', 'savings'],
        ['Loans', $totalLoans, 'bg-brand-700', 'loans'],
        ['Investments', $totalInvestmentBalance, 'bg-cyan-600', 'investments'],
        ['Drawer cash', $drawerBalance, 'bg-amber-500', 'teller'],
    ];
    $portfolioRows = array_values(array_filter($portfolioRows, fn (array $row): bool => $moduleEnabled($row[3])));
    $portfolioTotal = max(1, array_sum(array_column($portfolioRows, 1)));
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as [$label, $value, $badgeClass, $barClass, $spark, $href])
        <a href="{{ $href }}" class="df-panel df-hover-lift relative overflow-hidden p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $value }}</div>
                </div>
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl {{ $badgeClass }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path d="M5 19V5m0 14h14M9 16v-5m4 5V8m4 8v-7" />
                    </svg>
                </span>
            </div>
            <div class="mt-5 flex h-12 items-end gap-1.5">
                @foreach ($spark as $point)
                    <div class="flex-1 rounded-t-lg bg-gradient-to-t {{ $barClass }}" style="height: {{ $point }}%"></div>
                @endforeach
            </div>
        </a>
    @endforeach
</div>

@if($isCashierDashboard && $moduleEnabled('teller'))
<section class="df-panel mt-6 overflow-visible">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Recent Posted Transactions</h2>
            <p class="text-sm text-slate-500">Latest cashier postings. Reversal actions are restricted to admins and above.</p>
        </div>
        <a href="{{ route('reports.show', 'user-posted-transactions') }}" class="df-action-secondary">View all</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="p-3">Time</th>
                    <th>Reference</th>
                    <th>Account</th>
                    <th>Client</th>
                    <th>Type</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cashierRecentTransactions as $transaction)
                    @php
                        $pendingReversal = $transaction->reversalRequests->first();
                        $journalAccount = $transaction->journalEntries->first()?->chartAccount;
                    @endphp
                    <tr class="border-t border-slate-200 align-top">
                        <td class="p-3 whitespace-nowrap">{{ $transaction->posted_at?->format('d M H:i') ?? $transaction->created_at->format('d M H:i') }}</td>
                        <td><a class="font-semibold text-blue-700" href="{{ route('receipts.show', $transaction) }}">{{ $transaction->reference }}</a></td>
                        <td class="font-medium text-slate-950">{{ $transaction->account?->account_number ?? ($journalAccount?->code ?? 'Ledger') }}</td>
                        <td class="max-w-56">{{ $transaction->account?->client?->full_name ?? $transaction->account?->client?->business_name ?? ($journalAccount?->name ?? 'General ledger posting') }}</td>
                        <td>{{ $transaction->type?->name ?? 'Transaction' }}</td>
                        <td class="text-right font-semibold whitespace-nowrap">UGX {{ number_format((float) $transaction->amount, 2) }}</td>
                        <td>
                            @if($pendingReversal)
                                <span class="rounded bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">Reversal pending</span>
                            @elseif($transaction->status === 'reversed')
                                <span class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700">Reversed</span>
                            @elseif($transaction->reversal_lock_note)
                                <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ $transaction->reversal_lock_note }}</span>
                            @else
                                <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">{{ str($transaction->status)->headline() }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if($transaction->can_request_reversal)
                                <details class="relative inline-block text-left">
                                    <summary class="cursor-pointer list-none rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">Request reversal</summary>
                                    <form method="POST" action="{{ route('transactions.reversal-requests.store', $transaction) }}" class="absolute right-0 z-20 mt-2 grid w-72 gap-2 rounded-lg border border-amber-200 bg-white p-3 text-left shadow-xl">
                                        @csrf
                                        <label class="text-xs font-semibold text-slate-700">Reason
                                            <textarea name="reason" rows="3" class="mt-1 w-full rounded border-slate-300 text-sm" required></textarea>
                                        </label>
                                        <button class="rounded bg-amber-700 px-3 py-2 text-xs font-semibold text-white">Submit request</button>
                                    </form>
                                </details>
                            @else
                                <a class="text-sm font-semibold text-blue-700" href="{{ route('receipts.show', $transaction) }}">Receipt</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-8 text-center text-slate-500">No posted transactions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endif

@if($moduleEnabled('investments') && ! $isCompactDashboard)
<section class="df-panel mt-6 p-5">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Top Investment Plans</h2>
            <p class="text-sm text-slate-500">Plans ranked by current invested balance.</p>
        </div>
    </div>
    <div class="mt-4 grid gap-3 md:grid-cols-3">
        @forelse($topInvestmentPlans as $plan)
            <div class="df-soft-panel p-4">
                <div class="text-sm font-semibold text-slate-950">{{ $plan->name }}</div>
                <div class="mt-1 text-xs text-slate-500">{{ $plan->code }} · {{ str($plan->risk_level)->headline() }} risk</div>
                <div class="mt-3 text-lg font-semibold text-cyan-700">UGX {{ number_format($plan->investment_accounts_sum_current_balance ?? 0, 0) }}</div>
            </div>
        @empty
            <div class="df-soft-panel p-4 text-sm text-slate-500">No investment plan performance yet.</div>
        @endforelse
    </div>
</section>
@endif

@if($moduleEnabled('loans'))
<div class="mt-6 grid gap-5 xl:grid-cols-[1.2fr_0.8fr]">
    <section class="df-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Repayment Calendar</h2>
                <p class="text-sm text-slate-500">{{ $calendarMonth->format('F Y') }} next repayments distributed by scheduled due date.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canFilterCalendarBranch)
                    <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <select name="calendar_branch_id" class="h-10 rounded-lg border-slate-300 text-sm" onchange="this.form.submit()">
                            <option value="">All branches</option>
                            @foreach($calendarBranches as $branch)
                                <option value="{{ $branch->id }}" @selected((int) $selectedCalendarBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <a href="{{ route('due-repayments.index', array_filter(['branch_id' => $selectedCalendarBranchId])) }}" class="df-action-secondary">Today</a>
            </div>
        </div>
        <div class="mt-5 grid grid-cols-7 overflow-hidden rounded-t-lg bg-slate-800 text-center text-xs font-semibold uppercase tracking-wide text-white">
            @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                <div class="border-r border-white/10 px-2 py-3 last:border-r-0">{{ $weekday }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7 overflow-hidden rounded-b-lg border border-t-0 border-slate-200">
            @foreach($calendarDays as $day)
                <a href="{{ route('due-repayments.index', array_filter(['date' => $day['date']->toDateString(), 'branch_id' => $selectedCalendarBranchId])) }}" class="min-h-24 border-b border-r border-slate-200 p-2 text-left transition hover:bg-brand-50 {{ $day['upcoming_count'] > 0 ? 'bg-brand-600 text-white hover:text-slate-950' : ($day['overdue_count'] > 0 ? 'bg-red-600 text-white hover:text-slate-950' : 'bg-white text-slate-700') }} {{ $day['is_today'] ? 'bg-sky-100 text-slate-950 ring-2 ring-inset ring-brand-600' : '' }} {{ $day['is_current_month'] ? '' : 'opacity-40' }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-semibold">{{ $day['date']->day }}</span>
                        @if($day['date']->isSunday())
                            <span class="rounded bg-white/20 px-1.5 py-0.5 text-[10px] font-semibold">Off</span>
                        @endif
                    </div>
                    @if($day['count'] > 0)
                        <div class="mt-3 rounded bg-cyan-300/80 px-2 py-1 text-xs font-semibold text-slate-950">{{ $day['upcoming_count'] > 0 ? $day['upcoming_count'].' next' : $day['count'].' scheduled' }}</div>
                        <div class="mt-1 text-[11px] {{ $day['is_today'] ? 'text-slate-500' : ($day['open_count'] > 0 ? 'text-white/80' : 'text-slate-500') }}">
                            {{ $day['open_count'] }} open · UGX {{ number_format($day['amount'], 0) }}
                        </div>
                        @if($day['overdue_count'] > 0)
                            <div class="mt-1 rounded bg-white/20 px-2 py-0.5 text-[10px] font-semibold">{{ $day['overdue_count'] }} overdue</div>
                        @elseif($day['open_count'] === 0)
                            <div class="mt-1 rounded bg-white/20 px-2 py-0.5 text-[10px] font-semibold">Cleared</div>
                        @endif
                    @else
                        <div class="mt-3 text-xs text-slate-400">No schedules</div>
                    @endif
                </a>
            @endforeach
        </div>
    </section>

    <section class="df-panel p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Next Repayments</h2>
                <p class="text-sm text-slate-500">Upcoming clients expected to pay.</p>
            </div>
            <div class="text-right">
                <div class="text-2xl font-semibold text-slate-950">{{ $nextRepayments->count() }}</div>
                <div class="text-xs text-slate-500">Upcoming</div>
            </div>
        </div>
        <div class="mt-5 divide-y divide-slate-200">
            @forelse($nextRepayments as $schedule)
                <div class="py-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <a href="{{ route('loans.show', $schedule->loan) }}" class="font-semibold text-slate-950 hover:text-brand-700">{{ $schedule->loan->client->full_name }}</a>
                            <div class="mt-1 text-xs text-slate-500">{{ $schedule->loan->client->phone }}{{ $schedule->loan->client->alternative_phone ? ' / '.$schedule->loan->client->alternative_phone : '' }}</div>
                        </div>
                        <div class="text-right text-sm font-semibold text-slate-950">UGX {{ number_format($schedule->outstanding_amount, 0) }}</div>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span>{{ $schedule->due_date?->format('d M Y') }}</span>
                        <span>{{ $schedule->loan->loan_number }} · Installment #{{ $schedule->installment_number }}</span>
                    </div>
                </div>
            @empty
                <div class="py-10 text-center text-sm text-slate-500">No upcoming repayments are scheduled.</div>
            @endforelse
        </div>
        <a href="{{ route('due-repayments.index', array_filter(['branch_id' => $selectedCalendarBranchId])) }}" class="mt-4 inline-flex text-sm font-semibold text-brand-700">View due today</a>
    </section>
</div>
@endif

@if(($moduleEnabled('teller') && (! $isCompactDashboard || $isCashierDashboard)) || (count($portfolioRows) > 0 && ! $isCompactDashboard))
<div class="mt-6 grid gap-5 {{ $isCompactDashboard ? 'xl:grid-cols-1' : 'xl:grid-cols-[1.1fr_0.9fr]' }}">
    @if($moduleEnabled('teller') && (! $isCompactDashboard || $isCashierDashboard))
    <section class="df-panel p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">Cash Movement</h2>
                <p class="text-sm text-slate-500">Daily inflow, outflow, and available teller position.</p>
            </div>
            <span class="df-badge border-brand-500/20 bg-brand-50 text-brand-800">Today</span>
        </div>
        <div class="mt-6 grid h-64 grid-cols-3 items-end gap-4 border-b border-slate-200 px-2 pb-3">
            @foreach ($cashRows as [$label, $amount, $barClass])
                @php($height = max(8, ($amount / $cashMax) * 100))
                <div class="flex h-full flex-col justify-end gap-3">
                    <div class="rounded-t-2xl {{ $barClass }} shadow-lg shadow-slate-200" style="height: {{ $height }}%"></div>
                    <div>
                        <div class="text-xs font-semibold text-slate-700">{{ $label }}</div>
                        <div class="text-xs text-slate-500">{{ number_format($amount, 0) }} UGX</div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    @if(count($portfolioRows) > 0 && ! $isCompactDashboard)
    <section class="df-panel p-5">
        <h2 class="text-lg font-semibold text-slate-950">Portfolio Mix</h2>
        <p class="text-sm text-slate-500">Enabled financial modules summarized in one view.</p>
        <div class="mt-6 space-y-5">
            @foreach ($portfolioRows as [$label, $amount, $barClass])
                @php($width = ($amount / $portfolioTotal) * 100)
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-slate-700">{{ $label }}</span>
                        <span class="text-slate-500">{{ number_format($amount, 0) }} UGX</span>
                    </div>
                    <div class="h-3 rounded-full bg-slate-100">
                        <div class="h-3 rounded-full {{ $barClass }}" style="width: {{ $width }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    @endif
</div>
@endif

@if(! $isCompactDashboard)
<section class="df-panel mt-6 p-5">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">System Alerts</h2>
            <p class="text-sm text-slate-500">Items that need operational attention.</p>
        </div>
    </div>
    <div class="mt-4 grid gap-3 md:grid-cols-3">
        @foreach ($alerts as $label => $value)
            <div class="df-soft-panel p-4 text-sm">
                <div class="flex items-center gap-3">
                    <span class="grid h-9 w-9 place-items-center rounded-lg bg-white text-brand-800 shadow-sm">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 4.3 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0z"/></svg>
                    </span>
                    <div>
                        <div class="font-medium text-slate-600">{{ $label }}</div>
                        <div class="mt-1 font-semibold text-slate-950">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif
@endsection
