@extends('layouts.app')

@section('content')
@php
    $displayName = $client->full_name ?: $client->business_name ?: $client->client_number;
    $clientModules = $enabledModules ?? ($financialOverview['enabled_modules'] ?? []);
    $moduleEnabled = fn (string $slug): bool => in_array($slug, $clientModules, true);
    $documentsEnabled = $moduleEnabled('documents');
    $savingsEnabled = $moduleEnabled('savings');
    $loansEnabled = $moduleEnabled('loans');
    $investmentsEnabled = $moduleEnabled('investments');
    $sharesEnabled = $moduleEnabled('shares');
    $fixedDepositsEnabled = $moduleEnabled('fixed-deposits');
    $groupSavingsEnabled = $moduleEnabled('group-savings');
    $profilePhotoDocument = $documentsEnabled ? $client->documents->firstWhere('type', 'profile_photo') : null;
    $basicSavingsAccounts = $savingsEnabled ? $client->accounts->filter(fn ($account) => $account->accountType?->category === 'savings') : collect();
    $savingsPackages = $savingsEnabled ? $client->savingsAccounts : collect();
    $hasSavingsAccount = $basicSavingsAccounts->isNotEmpty();
    $loanAccounts = $loansEnabled ? $client->accounts->filter(fn ($account) => $account->accountType?->category === 'loan') : collect();
    $currentAccounts = ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? $client->accounts->filter(fn ($account) => $account->accountType?->category === 'current') : collect();
    $otherAccounts = ($savingsEnabled || $loansEnabled || $investmentsEnabled || $sharesEnabled || $fixedDepositsEnabled) ? $client->accounts->reject(fn ($account) => in_array($account->accountType?->category, ['savings', 'loan', 'current'], true)) : collect();
    $canCreateLoan = $loansEnabled && auth()->user()->can('create', \App\Models\Loan::class);
    $canDeleteClient = auth()->user()->can('delete', $client);
    $activeSavings = $basicSavingsAccounts->where('status', 'active')->count();
    $dormantSavings = $basicSavingsAccounts->where('status', 'dormant')->count();
    $activeCurrent = $currentAccounts->where('status', 'active')->count();
    $dormantCurrent = $currentAccounts->where('status', 'dormant')->count();
    $goodLoans = $loansEnabled ? $client->loans->whereIn('status', ['active', 'fully_paid', 'closed'])->count() : 0;
    $arrearsLoans = $loansEnabled ? $client->loans->whereIn('status', ['in_arrears', 'written_off'])->count() : 0;
    $newApplications = $loansEnabled ? $client->loans->whereIn('status', ['draft', 'under_review', 'submitted', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved']) : collect();
    $approvingApplications = $loansEnabled ? $client->loans->whereIn('status', ['under_review', 'submitted', 'pending_guarantor_approval', 'pending_collateral_verification'])->count() : 0;
    $approvedApplications = $loansEnabled ? $client->loans->where('status', 'approved')->count() : 0;
    $shareFunded = $sharesEnabled ? $client->shareAccounts->filter(fn ($account) => (float) $account->total_share_capital > 0)->count() : 0;
    $shareUnfunded = $sharesEnabled ? $client->shareAccounts->count() - $shareFunded : 0;
    $accountTiles = array_values(array_filter([
        $savingsEnabled ? [
            'title' => 'Savings Accounts',
            'count' => $basicSavingsAccounts->count(),
            'tone' => 'bg-emerald-600 text-white',
            'icon' => 'SA',
            'leftLabel' => 'Active',
            'leftValue' => $activeSavings,
            'rightLabel' => 'Dormant',
            'rightValue' => $dormantSavings,
            'href' => route('clients.savings-accounts', $client),
        ] : null,
        $loansEnabled ? [
            'title' => 'Loan Accounts',
            'count' => $client->loans->count(),
            'tone' => 'bg-white text-slate-700 border border-slate-200',
            'icon' => 'LA',
            'leftLabel' => 'In Good Standing',
            'leftValue' => $goodLoans,
            'rightLabel' => 'In Arrears',
            'rightValue' => $arrearsLoans,
            'href' => route('clients.loans', $client),
        ] : null,
        $loansEnabled ? [
            'title' => 'New Applications',
            'count' => $newApplications->count(),
            'tone' => 'bg-slate-700 text-white',
            'icon' => 'NA',
            'leftLabel' => 'Approving',
            'leftValue' => $approvingApplications,
            'rightLabel' => 'Approved',
            'rightValue' => $approvedApplications,
            'href' => $canCreateLoan ? route('loans.create', ['client_id' => $client->id]) : route('clients.loans', $client),
        ] : null,
        ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? [
            'title' => 'Current Accounts',
            'count' => $currentAccounts->count(),
            'tone' => 'bg-amber-500 text-white',
            'icon' => 'CA',
            'leftLabel' => 'Active',
            'leftValue' => $activeCurrent,
            'rightLabel' => 'Dormant',
            'rightValue' => $dormantCurrent,
            'href' => route('clients.accounts', $client),
        ] : null,
        $fixedDepositsEnabled ? [
            'title' => 'Term Deposits',
            'count' => 0,
            'tone' => 'bg-sky-100 text-sky-800',
            'icon' => 'TD',
            'leftLabel' => 'Active',
            'leftValue' => 0,
            'rightLabel' => 'Matured',
            'rightValue' => 0,
            'href' => route('reports.show', 'term-deposits-maturity-report'),
        ] : null,
        $sharesEnabled ? [
            'title' => 'Shares Accounts',
            'count' => $client->shareAccounts->count(),
            'tone' => 'bg-blue-600 text-white',
            'icon' => 'SH',
            'leftLabel' => 'Funded',
            'leftValue' => $shareFunded,
            'rightLabel' => 'Unfunded',
            'rightValue' => $shareUnfunded,
            'href' => route('reports.show', 'share-accounts'),
        ] : null,
    ]));
    $actionItems = array_filter([
        ['Client Account Dashboard', route('clients.show', $client), 'CD', 0],
        ['Transaction Authorisation Details', route('audit-logs.index', ['search' => $client->client_number]), 'TA', 0],
        ['Edit Client Details', route('clients.edit', $client), 'ED', null],
        ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? ['Open Account', route('accounts.create', ['client_id' => $client->id]), 'OA', $client->accounts->count()] : null,
        $savingsEnabled ? ['Savings Account', route('clients.savings-accounts', $client), 'SA', $basicSavingsAccounts->count()] : null,
        ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? ['Account Queries', route('clients.accounts', $client), 'AQ', $client->accounts->count()] : null,
        $canCreateLoan ? ['Loan Prospects', route('loans.create', ['client_id' => $client->id]), 'LP', $newApplications->count()] : null,
        $loansEnabled ? ['Due Repayments', route('due-repayments.index', ['search' => $client->client_number]), 'DR', $arrearsLoans] : null,
    ]);
    $kycChecks = [
        'identity_confirmed' => 'Identity confirmed',
        'address_confirmed' => 'Address confirmed',
        'photo_captured' => 'Photo captured',
        'signature_captured' => 'Signature captured',
        'next_of_kin_confirmed' => 'Next of kin confirmed',
    ];
    $documentTypes = [
        'profile_photo' => 'Profile Photo',
        'national_id_front' => 'National ID Front',
        'national_id_back' => 'National ID Back',
        'signature' => 'Signature',
        'loan_application' => 'Loan Application Form',
        'guarantor_form' => 'Guarantor Form',
        'collateral_document' => 'Collateral Document',
        'agreement' => 'Agreement',
        'receipt' => 'Receipt',
        'bank_slip' => 'Bank Slip',
        'mobile_money_proof' => 'Mobile Money Proof',
        'other' => 'Other',
    ];
    $moduleFlags = $financialOverview['module_flags'] ?? [];
    $financialMetrics = collect([
        ['label' => 'Total Savings', 'value' => $financialOverview['total_savings'] ?? 0, 'tone' => 'text-emerald-700', 'show' => $moduleFlags['savings'] ?? false],
        ['label' => 'Total Investments', 'value' => $financialOverview['total_investments'] ?? 0, 'tone' => 'text-sky-700', 'show' => $moduleFlags['investments'] ?? false],
        ['label' => 'Total Shares', 'value' => $financialOverview['total_shares'] ?? 0, 'tone' => 'text-blue-700', 'show' => $moduleFlags['shares'] ?? false],
        ['label' => 'Outstanding Loans', 'value' => $financialOverview['outstanding_loan_balance'] ?? 0, 'tone' => 'text-red-700', 'show' => $moduleFlags['loans'] ?? false],
        ['label' => 'Available Balance', 'value' => $financialOverview['available_balance'] ?? 0, 'tone' => 'text-slate-900', 'show' => $savingsEnabled || $investmentsEnabled],
        ['label' => 'Locked Savings', 'value' => $financialOverview['locked_savings'] ?? 0, 'tone' => 'text-amber-700', 'show' => $savingsEnabled && $loansEnabled],
        ['label' => 'Locked Investments', 'value' => $financialOverview['locked_investment_amount'] ?? 0, 'tone' => 'text-violet-700', 'show' => $investmentsEnabled && $loansEnabled],
        ['label' => 'Net Position', 'value' => $financialOverview['net_financial_position'] ?? 0, 'tone' => (($financialOverview['net_financial_position'] ?? 0) >= 0 ? 'text-emerald-700' : 'text-red-700'), 'show' => true],
    ])->filter(fn (array $metric): bool => $metric['show'])->values();
    $accountDropdowns = array_values(array_filter([
        $savingsEnabled ? ['Savings Accounts', array_filter([! $hasSavingsAccount ? ['Generate savings account', route('clients.savings-accounts.generate', $client), 'post'] : null, ['View accounts', route('clients.savings-accounts', $client), 'get']])] : null,
        $loansEnabled ? ['Loan Accounts', array_filter([$canCreateLoan ? ['New application', route('loans.create', ['client_id' => $client->id])] : null, ['View loans', route('clients.loans', $client)]])] : null,
        ($savingsEnabled || $loansEnabled || $investmentsEnabled) ? ['Current Accounts', [['Open current account', route('accounts.create', ['client_id' => $client->id])], ['View accounts', route('clients.accounts', $client)]]] : null,
        $sharesEnabled ? ['Shares Accounts', [['Shares report', route('reports.show', 'share-accounts')], ['Share balances', route('reports.show', 'share-balances')]]] : null,
        $fixedDepositsEnabled ? ['Term Deposits', [['Maturity report', route('reports.show', 'term-deposits-maturity-report')], ['Funded term deposits', route('reports.show', 'funded-term-deposit-accounts')]]] : null,
    ]));
    $accountsTabEnabled = count($accountTiles) > 0 || $savingsEnabled || $loansEnabled || $investmentsEnabled;
@endphp

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="df-title">Client Account</h1>
        <p class="df-muted">{{ $client->client_number }} · {{ $displayName }} · {{ $client->branch->name }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @foreach ($accountDropdowns as [$label, $links])
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
        @if($canDeleteClient)
            <details class="group relative">
                <summary class="min-h-10 cursor-pointer list-none rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800">Delete Client <span class="ml-1 text-xs">▾</span></summary>
                <form method="POST" action="{{ route('clients.destroy', $client) }}" class="absolute right-0 z-20 mt-2 grid w-80 gap-3 rounded-lg border border-red-200 bg-white p-4 text-sm shadow-xl">
                    @csrf
                    @method('DELETE')
                    <div>
                        <div class="font-semibold text-red-700">Permanent deletion</div>
                        <p class="mt-1 text-slate-600">This removes the client and connected accounts, loans, schedules, repayments, transactions, documents, notes, and related records.</p>
                    </div>
                    <label class="grid gap-1 font-medium text-slate-700">
                        Type client number to confirm
                        <input name="confirmation" class="rounded-lg border-slate-200 text-sm focus:border-red-500 focus:ring-red-100" placeholder="{{ $client->client_number }}" required>
                    </label>
                    <button class="rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800">Delete permanently</button>
                </form>
            </details>
        @endif
    </div>
</div>

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
                    <div><dt class="inline font-semibold">Branch:</dt> <dd class="inline">{{ $client->branch->name }}</dd></div>
                    <div><dt class="inline font-semibold">Status:</dt> <dd class="inline">{{ str($client->status)->headline() }}</dd></div>
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
        <section class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            @foreach($accountTiles as $tile)
                <a href="{{ $tile['href'] }}" class="df-hover-lift overflow-hidden rounded-card shadow-panel {{ $tile['tone'] }}">
                    <div class="flex items-start gap-5 p-5">
                        <div class="grid h-14 w-14 shrink-0 place-items-center rounded bg-white/90 text-sm font-bold text-slate-900 shadow-sm">{{ $tile['icon'] }}</div>
                        <div class="min-w-0 flex-1 text-right">
                            <div class="text-sm font-semibold uppercase text-current/65">{{ $tile['title'] }}</div>
                            <div class="mt-1 text-4xl font-semibold">{{ number_format($tile['count']) }}</div>
                        </div>
                    </div>
                    <div class="mx-5 border-t border-current/20"></div>
                    <div class="grid grid-cols-2 gap-4 p-5 text-sm">
                        <div>
                            <div class="font-semibold uppercase text-current/65">{{ $tile['leftLabel'] }}</div>
                            <div class="mt-1 text-2xl font-semibold">{{ number_format($tile['leftValue']) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold uppercase text-current/65">{{ $tile['rightLabel'] }}</div>
                            <div class="mt-1 text-2xl font-semibold">{{ number_format($tile['rightValue']) }}</div>
                        </div>
                    </div>
                </a>
            @endforeach
        </section>

        <div data-df-tabs class="grid gap-5">
            <div class="df-tablist" role="tablist">
                <button type="button" class="df-tab" data-df-tab-target="overview">Overview</button>
                @if($accountsTabEnabled)
                    <button type="button" class="df-tab" data-df-tab-target="accounts">Accounts</button>
                @endif
                @if($loansEnabled || $groupSavingsEnabled)
                    <button type="button" class="df-tab" data-df-tab-target="loans">Loans & Groups</button>
                @endif
                <button type="button" class="df-tab" data-df-tab-target="kyc">KYC & Documents</button>
                <button type="button" class="df-tab" data-df-tab-target="notes">Notes</button>
            </div>

            <section data-df-tab-panel="overview" class="grid gap-5 xl:grid-cols-3">
                <div class="df-panel p-5 xl:col-span-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-slate-950">Financial Overview</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                @if($loansEnabled)
                                    {{ number_format($financialOverview['active_loans'] ?? 0) }} active loan(s) included in this position.
                                @else
                                    Overview reflects the financial modules enabled for this organization.
                                @endif
                            </p>
                        </div>
                    </div>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($financialMetrics as $metric)
                            <div class="df-soft-panel p-4">
                                <dt class="text-xs font-semibold uppercase text-slate-500">{{ $metric['label'] }}</dt>
                                <dd class="mt-2 text-xl font-semibold {{ $metric['tone'] }}">UGX {{ number_format((float) $metric['value'], 2) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="df-panel p-5 xl:col-span-2">
                    <h2 class="font-semibold text-slate-950">Biodata</h2>
                    @if($errors->has('client_officer_id'))
                        <div class="mt-4 rounded border border-coral-500/20 bg-coral-50 p-3 text-sm text-coral-700">{{ $errors->first('client_officer_id') }}</div>
                    @endif
                    <dl class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">Phone</dt><dd class="mt-1 font-semibold">{{ $client->phone }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">Alternative phone</dt><dd class="mt-1">{{ $client->alternative_phone ?: 'N/A' }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">Email</dt><dd class="mt-1">{{ $client->email ?: 'N/A' }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">National ID</dt><dd class="mt-1">{{ $client->national_id ?: 'N/A' }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">DOB / Gender</dt><dd class="mt-1">{{ $client->date_of_birth?->format('d M Y') ?? 'N/A' }} · {{ $client->gender ? str($client->gender)->headline() : 'N/A' }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">Officer</dt><dd class="mt-1">{{ $client->officer?->name ?? 'Unassigned' }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">Occupation</dt><dd class="mt-1">{{ $client->occupation ?: 'N/A' }}</dd></div>
                        <div class="df-soft-panel p-3"><dt class="text-slate-500">Next of kin</dt><dd class="mt-1">{{ $client->next_of_kin_name ?: 'N/A' }} {{ $client->next_of_kin_phone ? '· '.$client->next_of_kin_phone : '' }}</dd></div>
                    </dl>
                    @if($canAssignClientOfficer)
                        <form method="POST" action="{{ route('clients.assign-officer', $client) }}" class="mt-5 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-[1fr_auto]">
                            @csrf
                            <label class="text-sm font-semibold text-slate-700">Assigned Loan Officer <x-required-badge />
                                <select name="officer_id" class="mt-1 w-full rounded border-slate-300" required>
                                    @foreach($assignableOfficers as $officer)
                                        <option value="{{ $officer->id }}" @selected((int) old('officer_id', $client->officer_id) === $officer->id)>{{ $officer->name }} · {{ $officer->role?->name }} · {{ $officer->branch?->name ?? 'No branch' }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="df-action-secondary self-end">Update Officer</button>
                        </form>
                    @endif
                </div>

                <div class="df-panel p-5">
                    <h2 class="font-semibold text-slate-950">Client Health</h2>
                    <div class="mt-4 grid gap-4">
                        <div>
                            <div class="flex items-center justify-between text-sm"><span class="text-slate-500">KYC completion</span><span class="font-semibold">{{ $client->kyc_completion_percentage }}%</span></div>
                            <div class="mt-2 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-600" style="width: {{ $client->kyc_completion_percentage }}%"></div></div>
                        </div>
                        <div class="df-soft-panel p-3 text-sm"><span class="text-slate-500">KYC status</span><div class="mt-1 font-semibold">{{ str($client->kyc_status)->headline() }}</div></div>
                        <div class="df-soft-panel p-3 text-sm"><span class="text-slate-500">Risk rating</span><div class="mt-1 font-semibold">{{ $client->risk_rating ? str($client->risk_rating)->headline() : 'Unrated' }}</div></div>
                    </div>
                </div>
            </section>

            @if($accountsTabEnabled)
            <section data-df-tab-panel="accounts" class="grid gap-5 lg:grid-cols-2">
                @if($savingsEnabled)
                <div class="df-panel p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold text-slate-950">Savings Accounts</h2>
                        @if(! $hasSavingsAccount)
                            <form method="POST" action="{{ route('clients.savings-accounts.generate', $client) }}">
                                @csrf
                                <button class="df-action-secondary min-h-9 px-3 py-1.5">Generate</button>
                            </form>
                        @endif
                    </div>
                    <div class="mt-4 grid gap-3">
                        @forelse($basicSavingsAccounts as $account)
                            <a href="{{ route('accounts.show', $account) }}" class="df-soft-panel df-hover-lift flex items-center justify-between gap-3 p-4 text-sm">
                                <span><span class="font-semibold text-slate-950">{{ $account->account_number }}</span><span class="ml-2 text-slate-500">{{ $account->accountType?->name }}</span></span>
                                <span class="font-semibold">UGX {{ number_format((float) $account->balance, 2) }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-slate-500">No savings accounts opened.</p>
                        @endforelse
                        @if($savingsPackages->isNotEmpty())
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-medium text-amber-800">
                                {{ $savingsPackages->count() }} savings product package(s) attached. Use Client Savings Account to remove package attachments.
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                <div class="df-panel p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold text-slate-950">Other Accounts</h2>
                        <a class="df-action-secondary min-h-9 px-3 py-1.5" href="{{ route('accounts.create', ['client_id' => $client->id]) }}">Open</a>
                    </div>
                    <div class="mt-4 grid gap-3">
                        @forelse($client->accounts as $account)
                            <a class="df-soft-panel df-hover-lift flex flex-wrap items-center justify-between gap-3 p-4 text-sm" href="{{ route('accounts.show', $account) }}">
                                <span><span class="font-semibold text-slate-950">{{ $account->account_number }}</span><span class="ml-2 text-slate-500">{{ $account->accountType->name }}</span></span>
                                <span class="font-semibold">UGX {{ number_format((float) $account->balance, 2) }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-slate-500">No legacy or current accounts opened.</p>
                        @endforelse
                    </div>
                </div>
            </section>
            @endif

            @if($loansEnabled || $groupSavingsEnabled)
            <section data-df-tab-panel="loans" class="grid gap-5">
                @if($loansEnabled)
                <div class="df-panel overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h2 class="font-semibold text-slate-950">Loan Accounts List</h2>
                            <p class="mt-1 text-sm text-slate-500">Each row is a separate loan account attached to this client.</p>
                        </div>
                        @can('create', \App\Models\Loan::class)
                            <a class="df-action-secondary min-h-9 px-3 py-1.5" href="{{ route('loans.create', ['client_id' => $client->id]) }}">New Loan</a>
                        @endcan
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-700 text-xs uppercase text-white">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Account Number</th>
                                    <th class="px-4 py-3 font-semibold">Product</th>
                                    <th class="px-4 py-3 font-semibold">Branch</th>
                                    <th class="px-4 py-3 font-semibold">Disbursement Date</th>
                                    <th class="px-4 py-3 text-right font-semibold">Disbursed Amount</th>
                                    <th class="px-4 py-3 text-right font-semibold">Outstanding</th>
                                    <th class="px-4 py-3 text-right font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($client->loans as $loan)
                                    @php
                                        $loanAccountNumber = $loan->loanAccount?->account_number ?? $loan->loan_number;
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
                                    @endphp
                                    <tr class="odd:bg-white even:bg-slate-50 hover:bg-brand-50/70">
                                        <td class="px-4 py-3 font-semibold text-brand-700">
                                            <a href="{{ route('loans.show', $loan) }}">{{ $loanAccountNumber }}</a>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">{{ $loan->product?->name ?? 'Loan product' }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $loan->branch?->name ?? $client->branch->name }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $loan->disbursed_at?->format('d-m-Y') ?? 'Not disbursed' }}</td>
                                        <td class="px-4 py-3 text-right font-medium text-slate-700">UGX {{ number_format((float) ($loan->approved_amount ?? $loan->requested_amount), 2) }}</td>
                                        <td class="px-4 py-3 text-right font-medium text-slate-700">UGX {{ number_format((float) ($loan->loanAccount?->balance ?? $loan->principal_balance), 2) }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusTone }}">{{ $statusLabel }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No loan accounts yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                @if($groupSavingsEnabled)
                <div class="df-panel p-5">
                    <h2 class="font-semibold text-slate-950">Group Membership</h2>
                    <div class="mt-4 grid gap-3">
                        @forelse($client->groups as $group)
                            <a href="{{ route('client-groups.show', $group) }}" class="df-soft-panel df-hover-lift flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                                <span class="font-semibold text-slate-950">{{ $group->group_number }}</span>
                                <span class="text-slate-500">{{ $group->name }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-slate-500">Not assigned to any group.</p>
                        @endforelse
                    </div>
                </div>
                @endif
            </section>
            @endif

            <section data-df-tab-panel="kyc" class="grid gap-5 xl:grid-cols-[1fr_1.1fr]">
                <div class="df-panel p-5">
                    <h2 class="font-semibold text-slate-950">KYC Checklist</h2>
                    <form method="POST" action="{{ route('clients.kyc.update', $client) }}" class="mt-4 grid gap-4">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($kycChecks as $key => $label)
                                <label class="df-soft-panel flex cursor-pointer items-center gap-3 p-3 text-sm font-medium transition hover:border-brand-300 hover:bg-brand-50">
                                    <input type="checkbox" name="kyc_checklist[]" value="{{ $key }}" @checked((bool) data_get($client->kyc_documents, $key))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                            <select name="kyc_status">
                                @foreach(['pending','verified','rejected'] as $status)
                                    <option value="{{ $status }}" @selected($client->kyc_status === $status)>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                            <select name="risk_rating">
                                <option value="">Unrated</option>
                                @foreach(['low','medium','high'] as $rating)
                                    <option value="{{ $rating }}" @selected($client->risk_rating === $rating)>{{ str($rating)->headline() }}</option>
                                @endforeach
                            </select>
                            <button class="df-action-primary">Update KYC</button>
                        </div>
                    </form>
                </div>

                @if($documentsEnabled)
                <div class="df-panel p-5">
                    <h2 class="font-semibold text-slate-950">Documents</h2>
                    <form method="POST" action="{{ route('clients.documents.store', $client) }}" enctype="multipart/form-data" class="mt-4 grid gap-3 md:grid-cols-[12rem_1fr]">
                        @csrf
                        <label class="text-sm font-semibold text-slate-700">Document type <x-required-badge /><select name="type" required>
                            @foreach($documentTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select></label>
                        <label class="text-sm font-semibold text-slate-700">Title <x-required-badge /><input name="title" placeholder="Document title" required></label>
                        <label class="text-sm font-semibold text-slate-700 md:col-span-2">Document <x-required-badge /><input name="document" type="file" class="mt-1 w-full" required></label>
                        <button class="df-action-secondary md:col-span-2">Upload Document</button>
                    </form>
                    <div class="mt-4 grid gap-3">
                        @forelse ($client->documents as $document)
                            <div class="df-soft-panel p-3 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <div><span class="font-semibold">{{ $document->title }}</span><span class="ml-2 text-slate-500">{{ str($document->type)->headline() }}</span></div>
                                    <a href="{{ route('clients.documents.download', [$client, $document]) }}" class="font-semibold text-blue-700">Download</a>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">No documents uploaded.</p>
                        @endforelse
                    </div>
                </div>
                @else
                    <div class="df-panel p-5">
                        <h2 class="font-semibold text-slate-950">Documents</h2>
                        <p class="mt-4 text-sm text-slate-500">Document management is not enabled for this organization.</p>
                    </div>
                @endif
            </section>

            <section data-df-tab-panel="notes" class="df-panel p-5">
                <h2 class="font-semibold text-slate-950">Notes</h2>
                <form method="POST" action="{{ route('clients.notes.store', $client) }}" class="mt-4 grid gap-3 md:grid-cols-[220px_1fr_auto]">
                    @csrf
                    <label class="text-sm font-semibold text-slate-700">Category <x-required-badge /><select name="category" required>
                        @foreach(['general','kyc','risk','collections','field_visit'] as $category)
                            <option value="{{ $category }}">{{ str($category)->headline() }}</option>
                        @endforeach
                    </select></label>
                    <label class="text-sm font-semibold text-slate-700">Note <x-required-badge /><textarea name="note" rows="2" placeholder="Add a client note" required></textarea></label>
                    <button class="df-action-primary self-start">Add Note</button>
                </form>
                <div class="mt-4 grid gap-3">
                    @forelse ($client->notes as $note)
                        <div class="df-soft-panel p-4 text-sm">
                            <div class="font-semibold text-slate-950">{{ str($note->category)->headline() }} <span class="font-normal text-slate-500">by {{ $note->user?->name ?? 'System' }}</span></div>
                            <p class="mt-2 leading-6 text-slate-700">{{ $note->note }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No notes recorded.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </main>
</div>
@endsection
