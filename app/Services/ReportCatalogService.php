<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\BranchDailySummary;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\DrawerMovement;
use App\Models\DrawerSession;
use App\Models\FixedDepositAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanRepayment;
use App\Models\LoanSchedule;
use App\Models\ShareAccount;
use App\Models\SystemSetting;
use App\Models\TellerDrawer;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ApiSecurity;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportCatalogService
{
    public function __construct(private BusinessCalendarService $calendar) {}

    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function grouped(?User $user = null): Collection
    {
        return collect($this->definitions($user))->groupBy('category');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(?User $user = null): array
    {
        $definitions = [
            $this->report('user-posted-transactions', 'Posted Transactions', 'User Posted Transactions', 'transactions', ['posted_by' => 'user']),
            $this->report('system-posted-transactions', 'Posted Transactions', 'System Posted Transactions', 'transactions', ['posted_by' => 'system']),
            $this->report('daily-teller-transactions', 'Posted Transactions', 'Daily Teller Transactions', 'teller_transactions'),
            $this->report('exchange-rates', 'Treasury', 'Exchange Rates', 'settings', ['setting' => 'currency'], false, false),
            $this->report('cash-balances', 'Treasury', 'Cash Balances', 'cash_balances', [], false),
            $this->report('drawer-balances-by-teller', 'Treasury', 'Drawer Balances By Teller', 'drawer_balances', [], true, false),
            $this->report('branch-period-summary', 'Treasury', 'Branch Period Summary', 'branch_period_summary', [], true, false),
            $this->report('client-register', 'Clients', 'Client Register', 'clients'),
            $this->report('new-clients', 'Clients', 'New Clients', 'clients', ['date_column' => 'registered_at']),
            $this->report('officer-entries', 'Clients', 'Officer Entries', 'officer_entries'),
            $this->report('active-clients', 'Clients', 'Active Clients', 'clients', ['status' => 'active'], false),
            $this->report('chart-of-accounts', 'Accounting', 'Chart Of Accounts', 'chart_accounts', [], false),
            $this->report('gl-account-transactions', 'Accounting', 'GL Account Transactions', 'journal_entries'),
            $this->report('gl-transaction-analysis', 'Accounting', 'GL Transaction Analysis', 'journal_analysis'),
            $this->report('income-accounts', 'Accounting', 'Income Accounts', 'ledger_section', ['types' => ['income'], 'label' => 'Income']),
            $this->report('expense-accounts', 'Accounting', 'Expense Accounts', 'ledger_section', ['types' => ['expense'], 'label' => 'Expenses']),
            $this->report('asset-accounts', 'Accounting', 'Asset Accounts', 'ledger_section', ['types' => ['asset'], 'label' => 'Assets']),
            $this->report('liability-accounts', 'Accounting', 'Liability Accounts', 'ledger_section', ['types' => ['liability'], 'label' => 'Liabilities']),
            $this->report('equity-accounts', 'Accounting', 'Equity Accounts', 'ledger_section', ['types' => ['equity'], 'label' => 'Equity']),
            $this->report('income-statement', 'Accounting', 'Income Statement', 'accounting_statement', ['statement' => 'income']),
            $this->report('trial-balance', 'Accounting', 'Trial Balance', 'accounting_statement', ['statement' => 'trial']),
            $this->report('balance-sheet', 'Accounting', 'Balance Sheet', 'accounting_statement', ['statement' => 'balance']),
            $this->report('application-fees-income', 'Profitability', 'Application Fees Income', 'application_fees_income'),
            $this->report('loan-interest-income', 'Profitability', 'Loan Interest Accumulated', 'loan_interest_income'),
            $this->report('profitability-summary', 'Profitability', 'Profitability Summary', 'profitability_summary'),
            $this->report('projected-profitability-summary', 'Profitability', 'Projected Profitability Summary', 'profitability_summary', ['interest_basis' => 'projected']),
            $this->report('branch-profitability-standing', 'Profitability', 'Branch Profitability Standing', 'branch_profitability_standing'),
            $this->report('opened-savings-accounts', 'Savings', 'Opened Savings Accounts', 'accounts', ['category' => 'savings', 'date_column' => 'opened_at']),
            $this->report('unfunded-savings-accounts', 'Savings', 'Unfunded Savings Accounts', 'accounts', ['category' => 'savings', 'max_balance' => 0], false),
            $this->report('active-savings-accounts', 'Savings', 'Active Savings Accounts', 'accounts', ['category' => 'savings', 'status' => 'active'], false),
            $this->report('dormant-savings-accounts', 'Savings', 'Dormant Savings Accounts', 'accounts', ['category' => 'savings', 'status' => 'dormant'], false),
            $this->report('reactivated-savings-accounts', 'Savings', 'Reactivated Savings Accounts', 'accounts', ['category' => 'savings', 'status' => 'active']),
            $this->report('closed-savings-accounts', 'Savings', 'Closed Savings Accounts', 'accounts', ['category' => 'savings', 'status' => 'closed']),
            $this->report('savings-accounts-by-balances', 'Savings', 'Savings Accounts By Balances', 'accounts', ['category' => 'savings'], false),
            $this->report('accounts-below-minimum-balance', 'Savings', 'Accounts Below Minimum Balance', 'accounts', ['category' => 'savings', 'max_balance' => 9999], false),
            $this->report('savings-accounts-with-holds', 'Savings', 'Savings Accounts With Holds', 'accounts', ['category' => 'savings', 'status' => 'on_hold'], false),
            $this->report('accounts-with-adjusted-minimum-balance', 'Savings', 'Accounts With Adjusted Minimum Balance', 'accounts', ['category' => 'savings'], false),
            $this->report('accounts-with-accrued-interest', 'Savings', 'Accounts With Accrued Interest', 'accounts', ['category' => 'savings'], false),
            $this->placeholder('created-savings-standing-orders', 'Savings', 'Created Savings Standing Orders'),
            $this->placeholder('active-savings-standing-orders', 'Savings', 'Active Savings Standing Orders'),
            $this->placeholder('closed-savings-standing-orders', 'Savings', 'Closed Savings Standing Orders'),
            $this->placeholder('cancelled-savings-standing-orders', 'Savings', 'Cancelled Savings Standing Orders'),
            $this->report('share-accounts', 'Shares', 'Share Accounts', 'shares', [], false),
            $this->report('share-balances', 'Shares', 'Share Balances', 'shares', [], false),
            $this->report('share-transactions', 'Shares', 'Share Transactions', 'shares', []),
            $this->report('loans-application-by-date', 'Loan Application', 'Loans Application By Date', 'loans', ['date_column' => 'application_date']),
            $this->report('appraisal-loan-applications', 'Loan Application', 'Appraisal Loan Applications', 'loans', ['status' => 'under_review']),
            $this->report('approval-loan-applications', 'Loan Application', 'Approval Loan Applications', 'loans', ['status' => 'submitted']),
            $this->report('approved-loan-applications', 'Loan Application', 'Approved Loan Applications', 'loans', ['status' => 'approved']),
            $this->report('cancelled-loan-applications', 'Loan Application', 'Cancelled Loan Applications', 'loans', ['status' => 'cancelled']),
            $this->report('rejected-loan-applications', 'Loan Application', 'Rejected Loan Applications', 'loans', ['status' => 'rejected']),
            $this->report('created-loan-accounts', 'Loan Accounts', 'Created Loan Accounts', 'accounts', ['category' => 'loan']),
            $this->report('loan-accounts-pending-disbursement', 'Loan Accounts', 'Loan Accounts Pending Disbursement', 'loans', ['status' => 'approved']),
            $this->report('loan-accounts-cancelled', 'Loan Accounts', 'Loan Accounts Cancelled', 'loans', ['status' => 'cancelled']),
            $this->report('loan-disbursements', 'Loan Accounts', 'Loan Disbursements', 'loan_disbursements'),
            $this->report('loan-disbursement-reversals', 'Loan Accounts', 'Loan Disbursement Reversals', 'loan_disbursements', ['status' => 'reversed']),
            $this->report('new-loan-account-cases', 'Loan Accounts', 'New Loan Account Cases', 'loans', ['date_column' => 'created_at']),
            $this->report('loan-accounts-in-good-standing', 'Loan Accounts', 'Loan Accounts In Good Standing', 'loans', ['status' => 'active'], false),
            $this->report('loan-accounts-in-arrears', 'Loan Accounts', 'Loan Accounts In Arrears', 'loan_schedules', ['arrears' => true], false),
            $this->report('loan-aging-report', 'Loan Accounts', 'Loan Aging Report', 'loan_schedules', ['arrears' => true], false),
            $this->report('portfolio-summary', 'Loan Accounts', 'Portfolio Summary', 'loan_summary', [], false),
            $this->report('portfolio-at-risk', 'Loan Accounts', 'Portfolio At Risk', 'loan_schedules', ['arrears' => true], false),
            $this->report('loan-book', 'Loan Accounts', 'Loan Book', 'loan_book', [], false),
            $this->report('loan-recovery', 'Loan Accounts', 'Loan Recovery', 'loan_repayments'),
            $this->report('loan-repayments-by-relations-officer', 'Loan Accounts', 'Loan Repayments By Relations Officer', 'loan_repayments'),
            $this->report('loan-accounts-paid-up', 'Loan Accounts', 'Loan Accounts Paid Up', 'loans', ['status_in' => ['closed', 'fully_paid']]),
            $this->report('closed-rescheduled-loans', 'Loan Accounts', 'Closed Rescheduled Loans', 'loans', ['status' => 'closed_rescheduled']),
            $this->report('opened-rescheduled-loans', 'Loan Accounts', 'Opened Rescheduled Loans', 'loans', ['status' => 'restructured']),
            $this->report('loans-written-off', 'Loan Accounts', 'Loans Written-Off', 'loans', ['status' => 'written_off']),
            $this->report('expected-loan-payments', 'Loan Accounts', 'Expected Loan Payments', 'loan_schedules', ['future' => true]),
            $this->report('unfunded-term-deposit-accounts', 'Term Deposits', 'Unfunded Term Deposit Accounts', 'term_deposits', ['status' => 'pending'], false),
            $this->report('funded-term-deposit-accounts', 'Term Deposits', 'Funded Term Deposit Accounts', 'term_deposits', ['status' => 'funded'], false),
            $this->report('active-term-deposit-accounts', 'Term Deposits', 'Active Term Deposit Accounts', 'term_deposits', ['status' => 'active'], false),
            $this->report('matured-term-deposit-accounts', 'Term Deposits', 'Matured Term Deposit Accounts', 'term_deposits', ['status' => 'matured'], false),
            $this->report('liquidated-term-deposit-accounts', 'Term Deposits', 'Liquidated Term Deposit Accounts', 'term_deposits', ['status' => 'liquidated'], false),
            $this->report('cancelled-term-deposit-accounts', 'Term Deposits', 'Cancelled Term Deposit Accounts', 'term_deposits', ['status' => 'cancelled'], false),
            $this->report('term-deposits-maturity-report', 'Term Deposits', 'Term Deposits Maturity Report', 'term_deposits', ['maturity' => true]),
            $this->report('current-accounts-register', 'Current Accounts', 'Current Accounts Register', 'accounts', ['category' => 'current'], false),
        ];

        if (! $user || $user->hasRole('super-admin') || ! $user->organization) {
            return $definitions;
        }

        $enabledModules = app(ModuleService::class)->enabledSlugs($user->organization);

        return array_values(array_filter(
            $definitions,
            fn (array $definition): bool => in_array($this->moduleForReport($definition), $enabledModules, true)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug, ?User $user = null): ?array
    {
        return collect($this->definitions($user))->firstWhere('slug', $slug);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(string $slug, array $filters, User $user): array
    {
        $definition = $this->find($slug, $user);

        abort_unless($definition, 404);

        $from = $definition['usesDates']
            ? (filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : today()->startOfDay())
            : Carbon::parse('1900-01-01')->startOfDay();
        $to = $definition['usesDates']
            ? (filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay())
            : Carbon::parse('2999-12-31')->endOfDay();
        $detail = (string) ($filters['detail'] ?? 'detailed');
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== '' ? (int) $filters['branch_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));
        $payload = $definition['payload'];
        $interestBasis = in_array(($filters['interest_basis'] ?? $payload['interest_basis'] ?? 'collected'), ['collected', 'projected'], true)
            ? (string) ($filters['interest_basis'] ?? $payload['interest_basis'] ?? 'collected')
            : 'collected';
        $limit = $detail === 'brief' ? 25 : 200;

        [$columns, $rows, $summary] = match ($definition['kind']) {
            'transactions' => $this->transactions($payload, $from, $to, $branchId, $limit, $user, $search),
            'teller_transactions' => $this->tellerTransactions($from, $to, $branchId, $limit, $user, $search),
            'cash_balances' => $this->cashBalances($branchId, $user),
            'drawer_balances' => $this->drawerBalances($from, $to, $branchId, $user),
            'branch_period_summary' => $this->branchPeriodSummary($from, $to, $branchId, $user),
            'clients' => $this->clients($payload, $from, $to, $branchId, $limit, $user),
            'officer_entries' => $this->officerEntries($from, $to, $branchId, $user),
            'chart_accounts' => $this->chartAccounts($branchId, $user),
            'journal_entries' => $this->journalEntries($from, $to, $branchId, $limit, $user),
            'journal_analysis' => $this->journalAnalysis($from, $to, $branchId, $user),
            'ledger_section' => $this->ledgerSection($payload, $from, $to, $branchId, $user),
            'accounting_statement' => $this->accountingStatement($payload, $from, $to, $branchId, $user),
            'application_fees_income' => $this->applicationFeesIncome($from, $to, $branchId, $limit, $user),
            'loan_interest_income' => $this->loanInterestIncome($from, $to, $branchId, $limit, $user, $interestBasis),
            'profitability_summary' => $this->profitabilitySummary($from, $to, $branchId, $user, $interestBasis),
            'branch_profitability_standing' => $this->branchProfitabilityStanding($from, $to, $branchId, $user, $interestBasis),
            'accounts' => $this->accounts($payload, $from, $to, $branchId, $limit, $user),
            'shares' => $this->shares($from, $to, $limit),
            'loans' => $this->loans($payload, $from, $to, $branchId, $limit, $user),
            'loan_disbursements' => $this->loanDisbursements($payload, $from, $to, $branchId, $limit, $user),
            'loan_schedules' => $this->loanSchedules($payload, $from, $to, $branchId, $limit, $user),
            'loan_summary' => $this->loanSummary($branchId, $user),
            'loan_book' => $this->loanBook($branchId, $detail === 'brief' ? 100 : 5000, $user),
            'loan_repayments' => $this->loanRepayments($from, $to, $branchId, $limit, $user),
            'term_deposits' => $this->termDeposits($payload, $from, $to, $limit),
            'settings' => $this->settings($payload),
            default => $this->placeholderRows($definition),
        };

        return compact('definition', 'columns', 'rows', 'summary', 'from', 'to', 'detail', 'branchId', 'search', 'interestBasis');
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function moduleForReport(array $definition): string
    {
        return match ($definition['category']) {
            'Clients' => 'clients',
            'Savings', 'Current Accounts' => 'savings',
            'Shares' => 'shares',
            'Term Deposits' => 'fixed-deposits',
            'Loan Application', 'Loan Accounts', 'Profitability' => 'loans',
            'Accounting' => 'accounting',
            'Treasury', 'Posted Transactions' => in_array($definition['kind'], ['journal_entries', 'journal_analysis', 'accounting_statement', 'chart_accounts'], true)
                ? 'accounting'
                : 'teller',
            default => 'reports',
        };
    }

    /**
     * @return array{0: array<int, string>, 1: Collection<int, array<string, mixed>>, 2: array<string, mixed>}
     */
    private function transactions(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user, string $search = ''): array
    {
        $query = Transaction::query()
            ->with(['account.client', 'account.branch', 'type', 'user'])
            ->whereBetween('posted_at', [$from, $to])
            ->when(($payload['posted_by'] ?? null) === 'user', fn (Builder $query): Builder => $query->whereNotNull('user_id'))
            ->when(($payload['posted_by'] ?? null) === 'system', fn (Builder $query): Builder => $query->whereNull('user_id'))
            ->when($search !== '', fn (Builder $query): Builder => $this->searchTransactions($query, $search))
            ->whereHas('account', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->latest('posted_at')->limit($limit)->get()->map(fn (Transaction $transaction): array => [
            'Date' => $transaction->posted_at?->format('d M Y H:i'),
            'Reference' => $transaction->reference,
            'Description' => $transaction->description ?? '',
            'Client' => $transaction->account?->client?->full_name ?? 'N/A',
            'Type' => $transaction->type?->name ?? 'N/A',
            'Amount' => $this->money((float) $transaction->amount),
            'Posted By' => $transaction->user?->name ?? 'System',
            '_links' => [
                'Reference' => route('receipts.show', $transaction),
            ],
        ]);

        return [['Date', 'Reference', 'Description', 'Client', 'Type', 'Amount', 'Posted By'], $rows, ['Total amount' => $this->money((float) $query->sum('amount'))]];
    }

    private function searchTransactions(Builder $query, string $search): Builder
    {
        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $query) use ($likeSearch): void {
            $query->whereAny(['description', 'reference', 'cheque_number', 'status'], 'like', $likeSearch)
                ->orWhereHas('account', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['account_number', 'account_name'], 'like', $likeSearch);
                })
                ->orWhereHas('account.client', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['client_number', 'first_name', 'middle_name', 'last_name', 'business_name', 'phone', 'alternative_phone'], 'like', $likeSearch);
                })
                ->orWhereHas('type', function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['name', 'code'], 'like', $likeSearch);
                });
        });
    }

    private function tellerTransactions(CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user, string $search = ''): array
    {
        $query = DrawerMovement::query()
            ->with(['session.drawer.branch', 'session.user', 'user'])
            ->whereBetween('created_at', [$from, $to])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $likeSearch = "%{$search}%";

                $query->where(function (Builder $query) use ($likeSearch): void {
                    $query->whereAny(['description', 'reference', 'type'], 'like', $likeSearch)
                        ->orWhereHas('session.user', fn (Builder $query): Builder => $query->where('name', 'like', $likeSearch))
                        ->orWhereHas('session.drawer', fn (Builder $query): Builder => $query->where('name', 'like', $likeSearch));
                });
            })
            ->whereHas('session.drawer', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user));

        $cashIn = (float) (clone $query)->where('type', 'cash_in')->sum('amount');
        $cashOut = (float) (clone $query)->where('type', 'cash_out')->sum('amount');
        $movements = (clone $query)->latest()->limit($limit)->get();
        $transactionsByReference = Transaction::query()
            ->whereIn('reference', $movements->pluck('reference')->filter()->unique())
            ->pluck('id', 'reference');
        $rows = $movements->map(fn (DrawerMovement $movement): array => [
            'Date' => $movement->created_at?->format('d M Y H:i'),
            'Branch' => $movement->session?->drawer?->branch?->name ?? 'N/A',
            'Teller / Cashier' => $movement->session?->user?->name ?? $movement->user?->name ?? 'N/A',
            'Drawer' => $movement->session?->drawer?->name ?? 'N/A',
            'Reference' => $movement->reference ?? 'N/A',
            'Direction' => $movement->type === 'cash_out' ? 'Cash Out' : 'Cash In',
            'Amount' => $this->money((float) $movement->amount),
            'Balance After' => $this->money((float) $movement->balance_after),
            'Description' => $movement->description ?? '',
            '_links' => filled($movement->reference) && $transactionsByReference->has($movement->reference) ? [
                'Reference' => route('receipts.show', $transactionsByReference->get($movement->reference)),
            ] : [],
        ]);

        return [
            ['Date', 'Branch', 'Teller / Cashier', 'Drawer', 'Reference', 'Direction', 'Amount', 'Balance After', 'Description'],
            $rows,
            [
                'Cash in' => $this->money($cashIn),
                'Cash out' => $this->money($cashOut),
                'Net cash movement' => $this->money($cashIn - $cashOut),
            ],
        ];
    }

    private function cashBalances(?int $branchId, User $user): array
    {
        $query = Account::query()
            ->with(['branch', 'accountType'])
            ->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', 'cash'))
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

        $rows = (clone $query)
            ->orderBy('account_number')
            ->get()
            ->map(fn (Account $account): array => [
                'Branch' => $account->branch?->name ?? 'N/A',
                'Account' => $account->account_number,
                'Name' => $account->account_name,
                'Balance' => $this->money((float) $account->balance),
                'Status' => Str::headline($account->status),
            ]);

        return [['Branch', 'Account', 'Name', 'Balance', 'Status'], $rows, ['Cash balance' => $this->money((float) $query->sum('balance'))]];
    }

    private function drawerBalances(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $sessions = DrawerSession::query()
            ->with(['drawer.branch', 'user'])
            ->whereBetween('opened_at', [$from, $to])
            ->whereHas('drawer', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user))
            ->orderByDesc('opened_at')
            ->get();
        $sessionRows = $sessions->map(fn (DrawerSession $session): array => [
            'Branch' => $session->drawer?->branch?->name ?? 'N/A',
            'Teller / Cashier' => $session->user?->name ?? 'N/A',
            'Drawer' => $session->drawer?->name ?? 'N/A',
            'Opened' => $session->opened_at?->format('d M Y H:i'),
            'Closed' => $session->closed_at?->format('d M Y H:i') ?? 'Open',
            'Opening' => $this->money((float) $session->opening_balance),
            'Expected' => $this->money((float) ($session->expected_balance ?? $session->drawer?->current_balance ?? 0)),
            'Closing' => $session->closing_balance === null ? 'Open' : $this->money((float) $session->closing_balance),
            'Variance' => $this->money((float) ($session->variance ?? 0)),
            'Status' => Str::headline($session->status),
        ]);

        if ($sessionRows->isNotEmpty()) {
            return [
                ['Branch', 'Teller / Cashier', 'Drawer', 'Opened', 'Closed', 'Opening', 'Expected', 'Closing', 'Variance', 'Status'],
                $sessionRows,
                [
                    'Sessions' => number_format($sessionRows->count()),
                    'Open sessions' => number_format($sessions->where('status', 'open')->count()),
                    'Current drawer cash' => $this->money((float) $sessions->pluck('drawer')->unique('id')->sum('current_balance')),
                ],
            ];
        }

        $drawers = TellerDrawer::query()
            ->with(['branch', 'assignedUser'])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->orderBy('code')
            ->get();
        $rows = $drawers->map(fn (TellerDrawer $drawer): array => [
            'Branch' => $drawer->branch?->name ?? 'N/A',
            'Teller / Cashier' => $drawer->assignedUser?->name ?? 'Unassigned',
            'Drawer' => $drawer->name,
            'Opened' => 'No session in period',
            'Closed' => 'N/A',
            'Opening' => $this->money(0),
            'Expected' => $this->money((float) $drawer->current_balance),
            'Closing' => $this->money((float) $drawer->current_balance),
            'Variance' => $this->money(0),
            'Status' => Str::headline($drawer->status),
        ]);

        return [
            ['Branch', 'Teller / Cashier', 'Drawer', 'Opened', 'Closed', 'Opening', 'Expected', 'Closing', 'Variance', 'Status'],
            $rows,
            ['Current drawer cash' => $this->money((float) $drawers->sum('current_balance'))],
        ];
    }

    private function branchPeriodSummary(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $query = BranchDailySummary::query()
            ->with('branch')
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

        $rows = (clone $query)
            ->orderBy('business_date')
            ->get()
            ->map(fn (BranchDailySummary $summary): array => [
                'Date' => $summary->business_date?->format('d M Y'),
                'Branch' => $summary->branch?->name ?? 'N/A',
                'Cash In' => $this->money((float) $summary->cash_in),
                'Cash Out' => $this->money((float) $summary->cash_out),
                'Expected Cash' => $this->money((float) $summary->expected_cash),
                'Counted Cash' => $this->money((float) $summary->counted_cash),
                'Variance' => $this->money((float) $summary->cash_variance),
                'Loan Repayments' => $this->money((float) $summary->loan_repayments),
                'Loan Disbursements' => $this->money((float) $summary->loan_disbursements),
                'Status' => Str::headline($summary->status),
            ]);

        return [
            ['Date', 'Branch', 'Cash In', 'Cash Out', 'Expected Cash', 'Counted Cash', 'Variance', 'Loan Repayments', 'Loan Disbursements', 'Status'],
            $rows,
            [
                'Cash in' => $this->money((float) (clone $query)->sum('cash_in')),
                'Cash out' => $this->money((float) (clone $query)->sum('cash_out')),
                'Loan repayments' => $this->money((float) (clone $query)->sum('loan_repayments')),
                'Branches reported' => number_format((clone $query)->distinct('branch_id')->count('branch_id')),
            ],
        ];
    }

    private function clients(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $dateColumn = $payload['date_column'] ?? 'created_at';
        $query = Client::query()
            ->with('branch')
            ->when($payload['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($dateColumn, fn (Builder $query, string $column): Builder => $query->whereBetween($column, [$from, $to]))
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->latest()->limit($limit)->get()->map(fn (Client $client): array => [
            'Client No.' => $client->client_number,
            'Name' => $client->full_name,
            'Phone' => $client->phone,
            'Branch' => $client->branch?->name ?? 'N/A',
            'Status' => Str::headline($client->status),
        ]);

        return [['Client No.', 'Name', 'Phone', 'Branch', 'Status'], $rows, ['Clients' => number_format($query->count())]];
    }

    private function officerEntries(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $officers = User::query()
            ->with(['role', 'branch'])
            ->where('organization_id', $user->organization_id)
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', '!=', 'member'))
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get();

        $rows = $officers->map(function (User $officer) use ($from, $to, $branchId, $user): array {
            $clients = Client::query()
                ->where('officer_id', $officer->id)
                ->whereBetween('created_at', [$from, $to])
                ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));
            $loans = Loan::query()
                ->where(function (Builder $query) use ($officer): void {
                    $query
                        ->where('created_by', $officer->id)
                        ->orWhere(fn (Builder $query): Builder => $query->whereNull('created_by')->where('officer_id', $officer->id));
                })
                ->whereBetween('created_at', [$from, $to])
                ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

            $outstandingBalance = (float) (clone $loans)->sum('principal_balance')
                + (float) (clone $loans)->sum('interest_balance')
                + (float) (clone $loans)->sum('fees_balance')
                + (float) (clone $loans)->sum('penalty_balance');

            return [
                'Officer' => $officer->name,
                'Role' => $officer->role?->name ?? 'N/A',
                'Branch' => $officer->branch?->name ?? 'N/A',
                'Clients Added' => number_format((clone $clients)->count()),
                'Loans Created' => number_format((clone $loans)->count()),
                'Commercial Loans' => number_format((clone $loans)->where('loan_type', 'commercial')->count()),
                'Approved Amount' => $this->money((float) (clone $loans)->sum('approved_amount')),
                'Outstanding Balance' => $this->money($outstandingBalance),
            ];
        })->filter(fn (array $row): bool => $row['Clients Added'] !== '0' || $row['Loans Created'] !== '0')->values();

        return [
            ['Officer', 'Role', 'Branch', 'Clients Added', 'Loans Created', 'Commercial Loans', 'Approved Amount', 'Outstanding Balance'],
            $rows,
            [
                'Officers with entries' => number_format($rows->count()),
                'Clients added' => number_format($rows->sum(fn (array $row): int => (int) str_replace(',', '', $row['Clients Added']))),
                'Loans created' => number_format($rows->sum(fn (array $row): int => (int) str_replace(',', '', $row['Loans Created']))),
            ],
        ];
    }

    private function chartAccounts(?int $branchId, User $user): array
    {
        $query = ChartAccount::query()
            ->with('branch')
            ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id)->orWhereNull('branch_id')));

        $rows = $query->orderBy('code')->get()->map(fn (ChartAccount $account): array => [
            'Code' => $account->code,
            'Name' => $account->name,
            'Type' => Str::headline($account->type),
            'Normal Balance' => Str::headline($account->normal_balance),
            'Branch' => $account->branch?->name ?? 'Shared',
        ]);

        return [['Code', 'Name', 'Type', 'Normal Balance', 'Branch'], $rows, ['Accounts' => number_format($rows->count())]];
    }

    private function journalEntries(CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = JournalEntry::query()
            ->with('chartAccount')
            ->whereBetween(DB::raw('coalesce(posted_at, created_at)'), [$from, $to])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->latest()->limit($limit)->get()->map(fn (JournalEntry $entry): array => [
            'Date' => ($entry->posted_at ?? $entry->created_at)?->format('d M Y'),
            'Reference' => $entry->reference,
            'Account' => $entry->chartAccount?->name ?? $entry->account_name,
            'Entry' => Str::headline($entry->entry_type),
            'Amount' => $this->money((float) $entry->amount),
            'Memo' => $entry->memo ?? $entry->description,
        ]);

        return [['Date', 'Reference', 'Account', 'Entry', 'Amount', 'Memo'], $rows, ['Entries' => number_format($query->count())]];
    }

    private function journalAnalysis(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $rows = JournalEntry::query()
            ->with('chartAccount')
            ->whereBetween(DB::raw('coalesce(posted_at, created_at)'), [$from, $to])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->selectRaw('chart_account_id, entry_type, count(*) as entries, sum(amount) as amount')
            ->groupBy('chart_account_id', 'entry_type')
            ->get()
            ->map(fn (JournalEntry $entry): array => [
                'Account' => $entry->chartAccount?->name ?? 'Unmapped',
                'Entry' => Str::headline($entry->entry_type),
                'Entries' => number_format((int) $entry->entries),
                'Amount' => $this->money((float) $entry->amount),
            ]);

        return [['Account', 'Entry', 'Entries', 'Amount'], $rows, ['Lines' => number_format($rows->count())]];
    }

    private function ledgerSection(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $types = $payload['types'] ?? ['asset', 'liability', 'equity', 'income', 'expense'];
        $label = (string) ($payload['label'] ?? 'Ledger');
        $balances = $this->ledgerBalances($types, $from, $to, $branchId, $user, false);

        $rows = $balances->map(fn (array $balance): array => [
            'Code' => $balance['code'],
            'Account' => $balance['account'],
            'Type' => Str::headline((string) $balance['type']),
            'Debit' => $this->money((float) $balance['debit']),
            'Credit' => $this->money((float) $balance['credit']),
            'Natural Balance' => $this->money((float) $balance['balance']),
            'Activity' => $balance['has_activity'] ? 'Posted' : 'No activity',
            '_links' => [
                'Account' => route('reports.show', [
                    'report' => 'gl-account-transactions',
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'branch_id' => $branchId,
                ]),
            ],
        ]);

        return [
            ['Code', 'Account', 'Type', 'Debit', 'Credit', 'Natural Balance', 'Activity'],
            $rows,
            [
                $label.' accounts' => number_format($rows->count()),
                'Debits' => $this->money((float) $balances->sum('debit')),
                'Credits' => $this->money((float) $balances->sum('credit')),
                'Natural balance' => $this->money((float) $balances->sum('balance')),
            ],
        ];
    }

    private function accountingStatement(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        return match ($payload['statement'] ?? 'trial') {
            'income' => $this->incomeStatement($from, $to, $branchId, $user),
            'balance' => $this->balanceSheet($from, $to, $branchId, $user),
            default => $this->trialBalance($from, $to, $branchId, $user),
        };
    }

    private function incomeStatement(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $income = $this->ledgerBalances(['income'], $from, $to, $branchId, $user, false);
        $expenses = $this->ledgerBalances(['expense'], $from, $to, $branchId, $user, false);
        $totalIncome = (float) $income->sum('balance');
        $totalExpenses = (float) $expenses->sum('balance');
        $netIncome = $totalIncome - $totalExpenses;
        $columns = ['Section', 'Code', 'Account', 'Debit', 'Credit', 'Amount'];

        $rows = $income
            ->map(fn (array $balance): array => $this->statementRow('Income', $balance, 'income-accounts', $from, $to, $branchId))
            ->concat([[
                'Section' => 'Income',
                'Code' => '',
                'Account' => 'Total Income',
                'Debit' => '',
                'Credit' => '',
                'Amount' => $this->money($totalIncome),
                '_is_total' => true,
                '_links' => ['Section' => $this->reportUrl('income-accounts', $from, $to, $branchId)],
            ]])
            ->concat($expenses->map(fn (array $balance): array => $this->statementRow('Expenses', $balance, 'expense-accounts', $from, $to, $branchId)))
            ->concat([
                [
                    'Section' => 'Expenses',
                    'Code' => '',
                    'Account' => 'Total Expenses',
                    'Debit' => '',
                    'Credit' => '',
                    'Amount' => $this->money($totalExpenses),
                    '_is_total' => true,
                    '_links' => ['Section' => $this->reportUrl('expense-accounts', $from, $to, $branchId)],
                ],
                [
                    'Section' => 'Profit / Loss',
                    'Code' => '',
                    'Account' => $netIncome >= 0 ? 'Net Income' : 'Net Loss',
                    'Debit' => '',
                    'Credit' => '',
                    'Amount' => $this->money($netIncome),
                    '_is_total' => true,
                ],
            ]);

        return [$columns, $rows, [
            'Income' => $this->money($totalIncome),
            'Expenses' => $this->money($totalExpenses),
            'Net income' => $this->money($netIncome),
            'Expense ratio' => $totalIncome > 0 ? number_format(($totalExpenses / $totalIncome) * 100, 2).'%' : '0.00%',
        ]];
    }

    private function balanceSheet(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $assets = $this->ledgerBalances(['asset'], $from, $to, $branchId, $user, true);
        $liabilities = $this->ledgerBalances(['liability'], $from, $to, $branchId, $user, true);
        $equity = $this->ledgerBalances(['equity'], $from, $to, $branchId, $user, true);
        $income = $this->ledgerBalances(['income'], $from, $to, $branchId, $user, true);
        $expenses = $this->ledgerBalances(['expense'], $from, $to, $branchId, $user, true);
        $retainedEarnings = (float) $income->sum('balance') - (float) $expenses->sum('balance');
        $totalAssets = (float) $assets->sum('balance');
        $totalLiabilities = (float) $liabilities->sum('balance');
        $totalEquity = (float) $equity->sum('balance') + $retainedEarnings;
        $columns = ['Section', 'Code', 'Account', 'Debit', 'Credit', 'Amount'];

        $rows = $assets
            ->map(fn (array $balance): array => $this->statementRow('Assets', $balance, 'asset-accounts', $from, $to, $branchId))
            ->concat([[
                'Section' => 'Assets',
                'Code' => '',
                'Account' => 'Total Assets',
                'Debit' => '',
                'Credit' => '',
                'Amount' => $this->money($totalAssets),
                '_is_total' => true,
                '_links' => ['Section' => $this->reportUrl('asset-accounts', $from, $to, $branchId)],
            ]])
            ->concat($liabilities->map(fn (array $balance): array => $this->statementRow('Liabilities', $balance, 'liability-accounts', $from, $to, $branchId)))
            ->concat([[
                'Section' => 'Liabilities',
                'Code' => '',
                'Account' => 'Total Liabilities',
                'Debit' => '',
                'Credit' => '',
                'Amount' => $this->money($totalLiabilities),
                '_is_total' => true,
                '_links' => ['Section' => $this->reportUrl('liability-accounts', $from, $to, $branchId)],
            ]])
            ->concat($equity->map(fn (array $balance): array => $this->statementRow('Equity', $balance, 'equity-accounts', $from, $to, $branchId)))
            ->concat([
                [
                    'Section' => 'Equity',
                    'Code' => '',
                    'Account' => 'Current / Retained Earnings',
                    'Debit' => '',
                    'Credit' => '',
                    'Amount' => $this->money($retainedEarnings),
                    '_is_total' => true,
                    '_links' => ['Section' => $this->reportUrl('income-statement', $from, $to, $branchId)],
                ],
                [
                    'Section' => 'Equity',
                    'Code' => '',
                    'Account' => 'Total Equity',
                    'Debit' => '',
                    'Credit' => '',
                    'Amount' => $this->money($totalEquity),
                    '_is_total' => true,
                    '_links' => ['Section' => $this->reportUrl('equity-accounts', $from, $to, $branchId)],
                ],
                [
                    'Section' => 'Balance Check',
                    'Code' => '',
                    'Account' => 'Assets - Liabilities - Equity',
                    'Debit' => '',
                    'Credit' => '',
                    'Amount' => $this->money($totalAssets - $totalLiabilities - $totalEquity),
                    '_is_total' => true,
                ],
            ]);

        return [$columns, $rows, [
            'Assets' => $this->money($totalAssets),
            'Liabilities' => $this->money($totalLiabilities),
            'Equity' => $this->money($totalEquity),
            'Balance check' => $this->money($totalAssets - $totalLiabilities - $totalEquity),
        ]];
    }

    private function trialBalance(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $balances = $this->ledgerBalances(['asset', 'liability', 'equity', 'income', 'expense'], $from, $to, $branchId, $user, true);
        $rows = $balances->map(fn (array $balance): array => [
            'Code' => $balance['code'],
            'Account' => $balance['account'],
            'Type' => Str::headline((string) $balance['type']),
            'Debit' => $this->money((float) $balance['debit']),
            'Credit' => $this->money((float) $balance['credit']),
            'Natural Balance' => $this->money((float) $balance['balance']),
        ]);

        $debits = (float) $balances->sum('debit');
        $credits = (float) $balances->sum('credit');

        return [['Code', 'Account', 'Type', 'Debit', 'Credit', 'Natural Balance'], $rows, [
            'Debits' => $this->money($debits),
            'Credits' => $this->money($credits),
            'Difference' => $this->money($debits - $credits),
        ]];
    }

    private function applicationFeesIncome(CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = LoanDisbursement::query()
            ->with(['branch', 'loan.client', 'disbursedBy'])
            ->whereBetween('disbursement_date', [$from, $to])
            ->where('fees_deducted', '>', 0)
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id));

        $rows = (clone $query)->orderByDesc('disbursement_date')->limit($limit)->get()->map(fn (LoanDisbursement $disbursement): array => [
            'Date' => $disbursement->disbursement_date?->format('d M Y'),
            'Branch' => $disbursement->branch?->name ?? 'N/A',
            'Loan' => $disbursement->loan?->loan_number ?? 'N/A',
            'Client' => $disbursement->loan?->client?->full_name ?? 'N/A',
            'Gross Disbursed' => $this->money((float) $disbursement->disbursed_amount),
            'Application Fee' => $this->money((float) $disbursement->fees_deducted),
            'Net To Savings' => $this->money((float) $disbursement->net_amount),
            'Disbursed By' => $disbursement->disbursedBy?->name ?? 'N/A',
        ]);

        return [
            ['Date', 'Branch', 'Loan', 'Client', 'Gross Disbursed', 'Application Fee', 'Net To Savings', 'Disbursed By'],
            $rows,
            [
                'Application fees' => $this->money((float) (clone $query)->sum('fees_deducted')),
                'Loans disbursed' => number_format((clone $query)->count()),
                'Gross disbursed' => $this->money((float) (clone $query)->sum('disbursed_amount')),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, array<string, mixed>>
     */
    private function ledgerBalances(array $types, CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user, bool $cumulative): Collection
    {
        $accounts = ChartAccount::query()
            ->whereIn('type', $types)
            ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id)->orWhereNull('branch_id')))
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return collect();
        }

        $start = $cumulative ? Carbon::parse('1900-01-01')->startOfDay() : $from;
        $totals = JournalEntry::query()
            ->whereIn('chart_account_id', $accounts->keys())
            ->whereBetween(DB::raw('coalesce(posted_at, created_at)'), [$start, $to])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->selectRaw("chart_account_id, sum(case when entry_type = 'debit' then amount else 0 end) as debit_total, sum(case when entry_type = 'credit' then amount else 0 end) as credit_total")
            ->groupBy('chart_account_id')
            ->get()
            ->keyBy('chart_account_id');

        return $accounts
            ->map(function (ChartAccount $account) use ($totals): array {
                $total = $totals->get($account->id);
                $debit = (float) ($total?->debit_total ?? 0);
                $credit = (float) ($total?->credit_total ?? 0);

                return [
                    'code' => $account->code,
                    'account' => $account->name,
                    'type' => $account->type,
                    'normal_balance' => $account->normal_balance,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $this->naturalBalance((string) $account->type, $debit, $credit),
                    'has_activity' => $debit > 0 || $credit > 0,
                ];
            })
            ->filter(fn (array $balance): bool => $balance['has_activity'])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed>
     */
    private function statementRow(string $section, array $balance, string $report, CarbonInterface $from, CarbonInterface $to, ?int $branchId): array
    {
        return [
            'Section' => $section,
            'Code' => $balance['code'],
            'Account' => $balance['account'],
            'Debit' => $this->money((float) $balance['debit']),
            'Credit' => $this->money((float) $balance['credit']),
            'Amount' => $this->money((float) $balance['balance']),
            '_links' => [
                'Section' => $this->reportUrl($report, $from, $to, $branchId),
                'Account' => $this->reportUrl('gl-account-transactions', $from, $to, $branchId),
            ],
        ];
    }

    private function reportUrl(string $report, CarbonInterface $from, CarbonInterface $to, ?int $branchId): string
    {
        return route('reports.show', array_filter([
            'report' => $report,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branch_id' => $branchId,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function naturalBalance(string $type, float $debit, float $credit): float
    {
        return match ($type) {
            'asset', 'expense' => $debit - $credit,
            default => $credit - $debit,
        };
    }

    private function loanInterestIncome(CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user, string $interestBasis): array
    {
        $query = LoanSchedule::query()
            ->with(['loan.client', 'loan.branch', 'loan.product'])
            ->excludingCancelledLoans()
            ->whereBetween('due_date', [$from, $to])
            ->whereHas('loan', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user));

        $rows = (clone $query)->orderBy('due_date')->limit($limit)->get()->map(function (LoanSchedule $schedule) use ($interestBasis): array {
            $projectedInterest = (float) $schedule->interest_due;
            $collectedInterest = (float) $schedule->interest_paid;

            return [
                'Due Date' => $schedule->due_date?->format('d M Y'),
                'Branch' => $schedule->loan?->branch?->name ?? 'N/A',
                'Loan' => $schedule->loan?->loan_number ?? 'N/A',
                'Client' => $schedule->loan?->client?->full_name ?? 'N/A',
                'Product' => $schedule->loan?->product?->name ?? 'N/A',
                'Installment' => '#'.$schedule->installment_number,
                'Projected Interest' => $this->money($projectedInterest),
                'Interest Collected' => $this->money($collectedInterest),
                'Interest Outstanding' => $this->money(max(0, $projectedInterest - $collectedInterest)),
                'Interest Shown' => $this->money($interestBasis === 'projected' ? $projectedInterest : $collectedInterest),
                'Status' => Str::headline($schedule->status),
            ];
        });

        $projectedInterest = (float) (clone $query)->sum('interest_due');
        $interestCollected = (float) (clone $query)->sum('interest_paid');
        $interestShown = $interestBasis === 'projected' ? $projectedInterest : $interestCollected;

        return [
            ['Due Date', 'Branch', 'Loan', 'Client', 'Product', 'Installment', 'Projected Interest', 'Interest Collected', 'Interest Outstanding', 'Interest Shown', 'Status'],
            $rows,
            [
                'Interest basis' => Str::headline($interestBasis),
                'Interest shown' => $this->money($interestShown),
                'Projected interest' => $this->money($projectedInterest),
                'Interest collected' => $this->money($interestCollected),
                'Interest outstanding' => $this->money(max(0, $projectedInterest - $interestCollected)),
            ],
        ];
    }

    private function profitabilitySummary(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user, string $interestBasis): array
    {
        $applicationFees = (float) LoanDisbursement::query()
            ->whereBetween('disbursement_date', [$from, $to])
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->sum('fees_deducted');
        $interestAccrued = (float) LoanSchedule::query()
            ->excludingCancelledLoans()
            ->whereBetween('due_date', [$from, $to])
            ->whereHas('loan', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user))
            ->sum('interest_due');
        $interestCollected = (float) LoanRepayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->sum('interest_paid');
        $journalIncome = $this->journalTypeNet('income', $from, $to, $branchId, $user);
        $expenses = $this->journalTypeNet('expense', $from, $to, $branchId, $user);
        $capitalDisbursed = (float) LoanDisbursement::query()
            ->whereBetween('disbursement_date', [$from, $to])
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->sum('disbursed_amount');
        $principalRecovered = (float) LoanRepayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
            ->sum('principal_paid');
        $loanCapitalOutstanding = (float) Loan::query()
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->sum('principal_balance');
        $interestShown = $interestBasis === 'projected' ? $interestAccrued : $interestCollected;
        $totalIncome = max($journalIncome, $applicationFees + $interestShown);
        $profit = $totalIncome - $expenses;
        $margin = $totalIncome > 0 ? ($profit / $totalIncome) * 100 : 0;

        $rows = collect([
            ['Section' => 'Income', 'Metric' => 'Application fees received', 'Amount' => $this->money($applicationFees), 'Note' => 'Fees deducted at disbursement.'],
            ['Section' => 'Income', 'Metric' => 'Projected interest', 'Amount' => $this->money($interestAccrued), 'Note' => 'All scheduled interest due in the period.'],
            ['Section' => 'Income', 'Metric' => 'Interest collected', 'Amount' => $this->money($interestCollected), 'Note' => 'Actual interest received through repayments.'],
            ['Section' => 'Income', 'Metric' => 'Interest shown in income', 'Amount' => $this->money($interestShown), 'Note' => $interestBasis === 'projected' ? 'Projected interest is included in income.' : 'Only collected interest is included in income.'],
            ['Section' => 'Income', 'Metric' => 'Ledger income total', 'Amount' => $this->money($journalIncome), 'Note' => 'Income accounts from the general ledger.'],
            ['Section' => 'Expense', 'Metric' => 'Ledger expenses total', 'Amount' => $this->money($expenses), 'Note' => 'Expense accounts from the general ledger.'],
            ['Section' => 'Capital', 'Metric' => 'Capital disbursed', 'Amount' => $this->money($capitalDisbursed), 'Note' => 'Gross loan principal disbursed.'],
            ['Section' => 'Capital', 'Metric' => 'Principal recovered', 'Amount' => $this->money($principalRecovered), 'Note' => 'Principal paid back in the period.'],
            ['Section' => 'Capital', 'Metric' => 'Loan capital outstanding', 'Amount' => $this->money($loanCapitalOutstanding), 'Note' => 'Current principal still in the field.'],
            ['Section' => 'Profit', 'Metric' => 'Net profit estimate', 'Amount' => $this->money($profit), 'Note' => 'Total income less ledger expenses.'],
        ]);

        return [
            ['Section', 'Metric', 'Amount', 'Note'],
            $rows,
            [
                'Interest basis' => Str::headline($interestBasis),
                'Total income' => $this->money($totalIncome),
                'Expenses' => $this->money($expenses),
                'Net profit' => $this->money($profit),
                'Profit margin' => number_format($margin, 2).'%',
            ],
        ];
    }

    private function branchProfitabilityStanding(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user, string $interestBasis): array
    {
        $branchMetrics = Branch::query()
            ->tap(fn (Builder $query) => ApiSecurity::scopeTenant($query, $user))
            ->when($branchId, fn (Builder $query): Builder => $query->whereKey($branchId))
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => $this->branchProfitabilityMetrics($from, $to, (int) $branch->getKey(), $user, $interestBasis) + [
                'branch' => $branch->name,
            ]);

        $totalMetrics = $branchMetrics->isEmpty()
            ? $this->branchProfitabilityMetrics($from, $to, $branchId, $user, $interestBasis) + ['branch' => 'All Branches']
            : $this->totalBranchProfitabilityMetrics($branchMetrics) + ['branch' => 'All Branches'];

        $rows = $branchMetrics
            ->push($totalMetrics)
            ->map(fn (array $metrics): array => $this->formatBranchProfitabilityMetrics($metrics));

        $columns = $interestBasis === 'projected'
            ? ['Branch', 'Income', 'Expenses', 'Total Loans', 'Interest', 'Projected Interest', 'Expected', 'Collected', 'Current Standing', 'Net']
            : ['Branch', 'Income', 'Expenses', 'Total Loans', 'Interest', 'Expected', 'Collected', 'Current Standing', 'Net'];

        return [
            $columns,
            $rows,
            [
                'Branches' => number_format(max(0, $rows->count() - 1)),
                'Interest basis' => Str::headline($interestBasis),
                'Income' => $this->money((float) $totalMetrics['income']),
                'Expenses' => $this->money((float) $totalMetrics['expenses']),
                'Total loans' => $this->money((float) $totalMetrics['total_loans']),
                'Interest' => $this->money((float) $totalMetrics['interest']),
                'Projected interest' => $this->money((float) $totalMetrics['projected_interest']),
                'Expected' => $this->money((float) $totalMetrics['expected']),
                'Collected' => $this->money((float) $totalMetrics['collected']),
                'Current standing' => $this->money((float) $totalMetrics['current_standing']),
                'Net' => $this->money((float) $totalMetrics['net']),
            ],
        ];
    }

    /**
     * @return array{income: float, expenses: float, total_loans: float, interest: float, projected_interest: float, expected: float, collected: float, current_standing: float, net: float}
     */
    private function branchProfitabilityMetrics(CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user, string $interestBasis): array
    {
        $applicationFees = (float) LoanDisbursement::query()
            ->whereBetween('disbursement_date', [$from, $to])
            ->where('status', 'successful')
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->sum('fees_deducted');
        $journalIncome = $this->journalTypeNet('income', $from, $to, $branchId, $user);
        $expenses = $this->journalTypeNet('expense', $from, $to, $branchId, $user);
        $interest = (float) LoanRepayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->where('status', 'successful')
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->sum('interest_paid');
        $projectedInterest = (float) LoanSchedule::query()
            ->excludingCancelledLoans()
            ->whereBetween('due_date', [$from, $to])
            ->whereHas('loan', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user))
            ->sum('interest_due');
        $collected = (float) LoanRepayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->where('status', 'successful')
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->sum('amount');
        $expected = (float) LoanSchedule::query()
            ->excludingCancelledLoans()
            ->whereBetween('due_date', [$from, $to])
            ->whereHas('loan', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user))
            ->sum('total_due');
        $totalLoans = (float) Loan::query()
            ->whereNotIn('status', ['draft', 'submitted', 'under_review', 'cancelled', 'rejected', 'written_off'])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->sum(DB::raw('coalesce(approved_amount, requested_amount)'));
        $currentStanding = (float) Loan::query()
            ->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured'])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->selectRaw('sum(principal_balance + interest_balance + fees_balance + penalty_balance) as standing')
            ->value('standing');
        $interestShown = $interestBasis === 'projected' ? $projectedInterest : $interest;
        $income = max($journalIncome, $applicationFees + $interestShown);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_loans' => $totalLoans,
            'interest' => $interestShown,
            'projected_interest' => $projectedInterest,
            'expected' => $expected,
            'collected' => $collected,
            'current_standing' => $currentStanding,
            'net' => $income - $expenses,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $branchMetrics
     * @return array{income: float, expenses: float, total_loans: float, interest: float, projected_interest: float, expected: float, collected: float, current_standing: float, net: float}
     */
    private function totalBranchProfitabilityMetrics(Collection $branchMetrics): array
    {
        return [
            'income' => (float) $branchMetrics->sum('income'),
            'expenses' => (float) $branchMetrics->sum('expenses'),
            'total_loans' => (float) $branchMetrics->sum('total_loans'),
            'interest' => (float) $branchMetrics->sum('interest'),
            'projected_interest' => (float) $branchMetrics->sum('projected_interest'),
            'expected' => (float) $branchMetrics->sum('expected'),
            'collected' => (float) $branchMetrics->sum('collected'),
            'current_standing' => (float) $branchMetrics->sum('current_standing'),
            'net' => (float) $branchMetrics->sum('net'),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function formatBranchProfitabilityMetrics(array $metrics): array
    {
        return [
            'Branch' => $metrics['branch'],
            'Income' => $this->money((float) $metrics['income']),
            'Expenses' => $this->money((float) $metrics['expenses']),
            'Total Loans' => $this->money((float) $metrics['total_loans']),
            'Interest' => $this->money((float) $metrics['interest']),
            'Projected Interest' => $this->money((float) $metrics['projected_interest']),
            'Expected' => $this->money((float) $metrics['expected']),
            'Collected' => $this->money((float) $metrics['collected']),
            'Current Standing' => $this->money((float) $metrics['current_standing']),
            'Net' => $this->money((float) $metrics['net']),
        ];
    }

    private function accounts(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = Account::query()
            ->with(['client', 'branch', 'accountType'])
            ->when($payload['category'] ?? null, fn (Builder $query, string $category): Builder => $query->whereHas('accountType', fn (Builder $query): Builder => $query->where('category', $category)))
            ->when($payload['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when(isset($payload['max_balance']), fn (Builder $query): Builder => $query->where('balance', '<=', $payload['max_balance']))
            ->when($payload['date_column'] ?? null, fn (Builder $query, string $column): Builder => $query->whereBetween($column, [$from, $to]))
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->orderByDesc('balance')->limit($limit)->get()->map(fn (Account $account): array => [
            'Account No.' => $account->account_number,
            'Name' => $account->account_name,
            'Client' => $account->client?->full_name ?? 'N/A',
            'Type' => $account->accountType?->name ?? 'N/A',
            'Branch' => $account->branch?->name ?? 'N/A',
            'Balance' => $this->money((float) $account->balance),
            'Status' => Str::headline($account->status),
        ]);

        return [['Account No.', 'Name', 'Client', 'Type', 'Branch', 'Balance', 'Status'], $rows, ['Balance' => $this->money((float) $query->sum('balance'))]];
    }

    private function shares(CarbonInterface $from, CarbonInterface $to, int $limit): array
    {
        $rows = ShareAccount::query()
            ->with(['client', 'product'])
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ShareAccount $account): array => [
                'Client' => $account->client?->full_name ?? 'N/A',
                'Product' => $account->product?->name ?? 'N/A',
                'Shares' => number_format((float) ($account->shares ?? 0)),
                'Share Value' => $this->money((float) $account->share_value),
                'Capital' => $this->money((float) $account->total_share_capital),
                'Status' => Str::headline((string) $account->status),
            ]);

        return [['Client', 'Product', 'Shares', 'Share Value', 'Capital', 'Status'], $rows, ['Share capital' => $this->money((float) ShareAccount::sum('total_share_capital'))]];
    }

    private function loans(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $dateColumn = $payload['date_column'] ?? 'created_at';
        $query = Loan::query()
            ->with(['client', 'product', 'officer'])
            ->when($payload['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($payload['status_in'] ?? null, fn (Builder $query, array $statuses): Builder => $query->whereIn('status', $statuses))
            ->when($dateColumn, fn (Builder $query, string $column): Builder => $query->whereBetween($column, [$from, $to]))
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->latest()->limit($limit)->get()->map(fn (Loan $loan): array => [
            'Loan No.' => $loan->loan_number,
            'Client' => $loan->client?->full_name ?? 'N/A',
            'Product' => $loan->product?->name ?? 'N/A',
            'Requested' => $this->money((float) $loan->requested_amount),
            'Approved' => $this->money((float) $loan->approved_amount),
            'Balance' => $this->money((float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance),
            'Status' => Str::headline($loan->status),
        ]);

        return [['Loan No.', 'Client', 'Product', 'Requested', 'Approved', 'Balance', 'Status'], $rows, ['Loans' => number_format($query->count())]];
    }

    private function loanDisbursements(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = LoanDisbursement::query()
            ->with(['loan.client', 'disbursedBy'])
            ->whereBetween('disbursement_date', [$from, $to])
            ->when($payload['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id));

        $rows = $query->latest()->limit($limit)->get()->map(fn (LoanDisbursement $disbursement): array => [
            'Date' => $disbursement->disbursement_date?->format('d M Y'),
            'Loan' => $disbursement->loan?->loan_number ?? 'N/A',
            'Client' => $disbursement->loan?->client?->full_name ?? 'N/A',
            'Gross' => $this->money((float) $disbursement->disbursed_amount),
            'Fees' => $this->money((float) $disbursement->fees_deducted),
            'Net' => $this->money((float) $disbursement->net_amount),
            'By' => $disbursement->disbursedBy?->name ?? 'N/A',
        ]);

        return [['Date', 'Loan', 'Client', 'Gross', 'Fees', 'Net', 'By'], $rows, ['Net disbursed' => $this->money((float) $query->sum('net_amount'))]];
    }

    private function loanBook(?int $branchId, int $limit, User $user): array
    {
        $columns = [
            'Product',
            'Name',
            'Gender',
            'Account',
            'Rescheduled',
            'Disbursement',
            'Maturity',
            'Interest Rate P.M',
            'Loan Principal',
            'Loan Interest',
            'Loan Fees',
            'Loan Total',
            'Installment Cycle',
            'Number Of Installments',
            'Paid Principal',
            'Paid Interest',
            'Paid Fees',
            'Paid Penalty',
            'Paid Total',
            'Overdue Principal',
            'Overdue Interest',
            'Overdue Fees',
            'Overdue Penalty',
            'Overdue Total',
            'Arrears Days',
            'Balance Principal',
            'Balance Interest',
            'Balance Fees',
            'Balance Penalty',
            'Balance Total',
            'Customer Relationship Officer',
        ];

        $loans = Loan::query()
            ->with(['client', 'product', 'loanAccount', 'officer', 'schedules'])
            ->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured', 'fully_paid', 'closed'])
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->orderBy('loan_product_id')
            ->orderBy('loan_number')
            ->limit($limit)
            ->get();

        $rows = $loans->map(function (Loan $loan): array {
            $schedules = $loan->schedules;
            $overdueSchedules = $schedules
                ->where('outstanding_amount', '>', 0)
                ->filter(fn (LoanSchedule $schedule): bool => $schedule->status === 'overdue' || $schedule->due_date?->lt(today()));
            $arrearsDays = (int) $overdueSchedules->max(fn (LoanSchedule $schedule): int => (int) ($schedule->days_overdue ?: ($schedule->due_date ? $this->calendar->businessDaysBetween($schedule->due_date, today()) : 0)));
            $isArrears = in_array($loan->status, ['active', 'in_arrears'], true)
                && ($overdueSchedules->isNotEmpty() || $loan->status === 'in_arrears');
            $principalDue = (float) $schedules->sum('principal_due');
            $interestDue = (float) $schedules->sum('interest_due');
            $feesDue = (float) $schedules->sum('fees_due');
            $penaltyDue = (float) $schedules->sum('penalty_due');
            $paidPrincipal = (float) $schedules->sum('principal_paid');
            $paidInterest = (float) $schedules->sum('interest_paid');
            $paidFees = (float) $schedules->sum('fees_paid');
            $paidPenalty = (float) $schedules->sum('penalty_paid');
            $overduePrincipal = $this->remainingScheduleBucket($overdueSchedules, 'principal');
            $overdueInterest = $this->remainingScheduleBucket($overdueSchedules, 'interest');
            $overdueFees = $this->remainingScheduleBucket($overdueSchedules, 'fees');
            $overduePenalty = $this->remainingScheduleBucket($overdueSchedules, 'penalty');
            $balancePrincipal = max(0, $principalDue - $paidPrincipal);
            $balanceInterest = max(0, $interestDue - $paidInterest);
            $balanceFees = max(0, $feesDue - $paidFees);
            $balancePenalty = max(0, $penaltyDue - $paidPenalty);

            return [
                'Product' => $loan->product?->name ?? 'N/A',
                'Name' => $loan->client?->full_name ?? 'N/A',
                'Gender' => Str::headline((string) ($loan->client?->gender ?? '')),
                'Account' => $loan->loanAccount?->account_number ?? $loan->loan_number,
                'Rescheduled' => in_array($loan->status, ['restructured', 'closed_rescheduled'], true) ? 'Yes' : 'No',
                'Disbursement' => $loan->disbursed_at?->format('d-m-Y'),
                'Maturity' => $schedules->max('due_date')?->format('d-m-Y'),
                'Interest Rate P.M' => $this->periodicInterestRate($loan),
                'Loan Principal' => $this->amount($principalDue ?: (float) ($loan->approved_amount ?? $loan->requested_amount)),
                'Loan Interest' => $this->amount($interestDue),
                'Loan Fees' => $this->amount($feesDue),
                'Loan Total' => $this->amount($principalDue + $interestDue + $feesDue),
                'Installment Cycle' => $this->installmentCycle($loan),
                'Number Of Installments' => (string) $schedules->count(),
                'Paid Principal' => $this->amount($paidPrincipal),
                'Paid Interest' => $this->amount($paidInterest),
                'Paid Fees' => $this->amount($paidFees),
                'Paid Penalty' => $this->amount($paidPenalty),
                'Paid Total' => $this->amount($paidPrincipal + $paidInterest + $paidFees + $paidPenalty),
                'Overdue Principal' => $this->amount($overduePrincipal),
                'Overdue Interest' => $this->amount($overdueInterest),
                'Overdue Fees' => $this->amount($overdueFees),
                'Overdue Penalty' => $this->amount($overduePenalty),
                'Overdue Total' => $this->amount($overduePrincipal + $overdueInterest + $overdueFees + $overduePenalty),
                'Arrears Days' => (string) $arrearsDays,
                'Balance Principal' => $this->amount($balancePrincipal),
                'Balance Interest' => $this->amount($balanceInterest),
                'Balance Fees' => $this->amount($balanceFees),
                'Balance Penalty' => $this->amount($balancePenalty),
                'Balance Total' => $this->amount($balancePrincipal + $balanceInterest + $balanceFees + $balancePenalty),
                'Customer Relationship Officer' => $loan->officer?->name ?? 'Unassigned',
                '_is_arrears' => $isArrears,
            ];
        });

        return [$columns, $rows, [
            'Loans' => number_format($rows->count()),
            'Loans in arrears' => number_format($rows->where('_is_arrears', true)->count()),
            'Balance total' => $this->money($loans->sum(fn (Loan $loan): float => (float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance)),
        ]];
    }

    private function loanSchedules(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = LoanSchedule::query()
            ->with(['loan.client', 'loan.product'])
            ->excludingCancelledLoans()
            ->when($payload['arrears'] ?? false, fn (Builder $query): Builder => $query->forActiveArrearsLoans())
            ->where('outstanding_amount', '>', 0)
            ->when($payload['arrears'] ?? false, fn (Builder $query): Builder => $query->whereDate('due_date', '<', today()))
            ->when($payload['future'] ?? false, fn (Builder $query): Builder => $query->whereBetween('due_date', [$from, $to]))
            ->whereHas('loan', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->orderBy('due_date')->limit($limit)->get()->map(fn (LoanSchedule $schedule): array => [
            'Due Date' => $schedule->due_date?->format('d M Y'),
            'Loan' => $schedule->loan?->loan_number ?? 'N/A',
            'Client' => $schedule->loan?->client?->full_name ?? 'N/A',
            'Installment' => '#'.$schedule->installment_number,
            'Outstanding' => $this->money((float) $schedule->outstanding_amount),
            'Days Due' => number_format((int) ($schedule->days_overdue ?: ($schedule->due_date ? $this->calendar->businessDaysBetween($schedule->due_date, now()) : 0))),
            'Status' => Str::headline($schedule->status),
        ]);

        return [['Due Date', 'Loan', 'Client', 'Installment', 'Outstanding', 'Days Due', 'Status'], $rows, ['Outstanding' => $this->money((float) $query->sum('outstanding_amount'))]];
    }

    private function loanSummary(?int $branchId, User $user): array
    {
        $rows = Loan::query()
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->selectRaw('status, count(*) as loans, sum(principal_balance) as principal, sum(interest_balance) as interest, sum(penalty_balance) as penalty')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (Loan $loan): array => [
                'Status' => Str::headline($loan->status),
                'Loans' => number_format((int) $loan->loans),
                'Principal' => $this->money((float) $loan->principal),
                'Interest' => $this->money((float) $loan->interest),
                'Penalty' => $this->money((float) $loan->penalty),
            ]);

        return [['Status', 'Loans', 'Principal', 'Interest', 'Penalty'], $rows, ['Statuses' => number_format($rows->count())]];
    }

    private function loanRepayments(CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = LoanRepayment::query()
            ->with(['loan.client', 'receivedBy'])
            ->whereBetween('paid_at', [$from, $to])
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->when(! $branchId && ! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id));

        $rows = $query->latest()->limit($limit)->get()->map(fn (LoanRepayment $repayment): array => [
            'Date' => $repayment->paid_at?->format('d M Y'),
            'Loan' => $repayment->loan?->loan_number ?? 'N/A',
            'Client' => $repayment->loan?->client?->full_name ?? 'N/A',
            'Amount' => $this->money((float) $repayment->amount),
            'Principal' => $this->money((float) $repayment->principal_paid),
            'Interest' => $this->money((float) $repayment->interest_paid),
            'Officer' => $repayment->receivedBy?->name ?? 'N/A',
        ]);

        return [['Date', 'Loan', 'Client', 'Amount', 'Principal', 'Interest', 'Officer'], $rows, ['Recovered' => $this->money((float) $query->sum('amount'))]];
    }

    private function termDeposits(array $payload, CarbonInterface $from, CarbonInterface $to, int $limit): array
    {
        $query = FixedDepositAccount::query()
            ->with(['client', 'product'])
            ->when($payload['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($payload['maturity'] ?? false, fn (Builder $query): Builder => $query->whereBetween('maturity_date', [$from, $to]));

        $rows = $query->orderBy('maturity_date')->limit($limit)->get()->map(fn (FixedDepositAccount $account): array => [
            'Client' => $account->client?->full_name ?? 'N/A',
            'Product' => $account->product?->name ?? 'N/A',
            'Amount' => $this->money((float) $account->amount),
            'Start' => $account->start_date?->format('d M Y'),
            'Maturity' => $account->maturity_date?->format('d M Y'),
            'Interest' => $this->money((float) $account->interest_accrued),
            'Status' => Str::headline((string) $account->status),
        ]);

        return [['Client', 'Product', 'Amount', 'Start', 'Maturity', 'Interest', 'Status'], $rows, ['Deposits' => number_format($query->count())]];
    }

    private function settings(array $payload): array
    {
        $value = SystemSetting::value($payload['setting'] ?? 'currency', 'UGX');
        $rows = collect([['Setting' => Str::headline((string) ($payload['setting'] ?? 'currency')), 'Value' => $value]]);

        return [['Setting', 'Value'], $rows, ['Records' => '1']];
    }

    private function placeholderRows(array $definition): array
    {
        return [
            ['Report', 'Status', 'Note'],
            collect([[
                'Report' => $definition['title'],
                'Status' => 'Available',
                'Note' => 'This report is registered. No source records are currently configured for this module.',
            ]]),
            ['Records' => '0'],
        ];
    }

    private function scopeBranch(Builder $query, ?int $branchId, User $user): Builder
    {
        if ($branchId) {
            return $query->where('branch_id', $branchId);
        }

        if (! ApiSecurity::hasGlobalBranchAccess($user) && $user->branch_id) {
            return $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    private function journalTypeNet(string $type, CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): float
    {
        return (float) JournalEntry::query()
            ->whereBetween(DB::raw('coalesce(posted_at, created_at)'), [$from, $to])
            ->whereHas('chartAccount', fn (Builder $query): Builder => $query->where('type', $type))
            ->tap(fn (Builder $query) => $this->scopeBranch($query, $branchId, $user))
            ->get()
            ->sum(function (JournalEntry $entry) use ($type): float {
                $amount = (float) $entry->amount;

                return match ($type) {
                    'expense' => $entry->entry_type === 'debit' ? $amount : -$amount,
                    default => $entry->entry_type === 'credit' ? $amount : -$amount,
                };
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $slug, string $category, string $title, string $kind, array $payload = [], bool $usesDates = true, bool $hasDetail = true): array
    {
        return compact('slug', 'category', 'title', 'kind', 'payload', 'usesDates', 'hasDetail');
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholder(string $slug, string $category, string $title): array
    {
        return $this->report($slug, $category, $title, 'placeholder', [], true, true);
    }

    private function money(float $amount): string
    {
        return 'UGX '.number_format($amount, 2);
    }

    private function amount(float $amount): string
    {
        return number_format($amount, 2);
    }

    /**
     * @param  Collection<int, LoanSchedule>  $schedules
     */
    private function remainingScheduleBucket(Collection $schedules, string $bucket): float
    {
        return (float) $schedules->sum(fn (LoanSchedule $schedule): float => max(0, (float) $schedule->getAttribute("{$bucket}_due") - (float) $schedule->getAttribute("{$bucket}_paid")));
    }

    private function periodicInterestRate(Loan $loan): string
    {
        $rate = (float) $loan->interest_rate;
        $periodicRate = match ($loan->repayment_frequency) {
            'daily' => $rate / 365,
            'weekly' => $rate / 52,
            default => $rate / 12,
        };

        return number_format($periodicRate, 2);
    }

    private function installmentCycle(Loan $loan): string
    {
        return match ($loan->repayment_frequency) {
            'daily' => '1 Day(s)',
            'weekly' => '1 Week(s)',
            default => '1 Month(s)',
        };
    }
}
