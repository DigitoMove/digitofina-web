<?php

namespace App\Services;

use App\Models\Account;
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
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportCatalogService
{
    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function grouped(): Collection
    {
        return collect($this->definitions())->groupBy('category');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
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
            $this->report('income-statement', 'Accounting', 'Income Statement', 'accounting_statement', ['statement' => 'income']),
            $this->report('trial-balance', 'Accounting', 'Trial Balance', 'accounting_statement', ['statement' => 'trial']),
            $this->report('balance-sheet', 'Accounting', 'Balance Sheet', 'accounting_statement', ['statement' => 'balance']),
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
            $this->report('loan-accounts-paid-up', 'Loan Accounts', 'Loan Accounts Paid Up', 'loans', ['status' => 'fully_paid']),
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
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return collect($this->definitions())->firstWhere('slug', $slug);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(string $slug, array $filters, User $user): array
    {
        $definition = $this->find($slug);

        abort_unless($definition, 404);

        $from = $definition['usesDates']
            ? (filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : today()->startOfDay())
            : Carbon::parse('1900-01-01')->startOfDay();
        $to = $definition['usesDates']
            ? (filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay())
            : Carbon::parse('2999-12-31')->endOfDay();
        $detail = (string) ($filters['detail'] ?? 'detailed');
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== '' ? (int) $filters['branch_id'] : null;
        $limit = $detail === 'brief' ? 25 : 200;
        $payload = $definition['payload'];

        [$columns, $rows, $summary] = match ($definition['kind']) {
            'transactions' => $this->transactions($payload, $from, $to, $branchId, $limit, $user),
            'teller_transactions' => $this->tellerTransactions($from, $to, $branchId, $limit, $user),
            'cash_balances' => $this->cashBalances($branchId, $user),
            'drawer_balances' => $this->drawerBalances($from, $to, $branchId, $user),
            'branch_period_summary' => $this->branchPeriodSummary($from, $to, $branchId, $user),
            'clients' => $this->clients($payload, $from, $to, $branchId, $limit, $user),
            'officer_entries' => $this->officerEntries($from, $to, $branchId, $user),
            'chart_accounts' => $this->chartAccounts($branchId, $user),
            'journal_entries' => $this->journalEntries($from, $to, $branchId, $limit, $user),
            'journal_analysis' => $this->journalAnalysis($from, $to, $branchId, $user),
            'accounting_statement' => $this->accountingStatement($payload, $from, $to, $branchId, $user),
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

        return compact('definition', 'columns', 'rows', 'summary', 'from', 'to', 'detail', 'branchId');
    }

    /**
     * @return array{0: array<int, string>, 1: Collection<int, array<string, mixed>>, 2: array<string, mixed>}
     */
    private function transactions(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = Transaction::query()
            ->with(['account.client', 'account.branch', 'type', 'user'])
            ->whereBetween('posted_at', [$from, $to])
            ->when(($payload['posted_by'] ?? null) === 'user', fn (Builder $query): Builder => $query->whereNotNull('user_id'))
            ->when(($payload['posted_by'] ?? null) === 'system', fn (Builder $query): Builder => $query->whereNull('user_id'))
            ->whereHas('account', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user));

        $rows = $query->latest('posted_at')->limit($limit)->get()->map(fn (Transaction $transaction): array => [
            'Date' => $transaction->posted_at?->format('d M Y H:i'),
            'Reference' => $transaction->reference,
            'Client' => $transaction->account?->client?->full_name ?? 'N/A',
            'Type' => $transaction->type?->name ?? 'N/A',
            'Amount' => $this->money((float) $transaction->amount),
            'Posted By' => $transaction->user?->name ?? 'System',
        ]);

        return [['Date', 'Reference', 'Client', 'Type', 'Amount', 'Posted By'], $rows, ['Total amount' => $this->money((float) $query->sum('amount'))]];
    }

    private function tellerTransactions(CarbonInterface $from, CarbonInterface $to, ?int $branchId, int $limit, User $user): array
    {
        $query = DrawerMovement::query()
            ->with(['session.drawer.branch', 'session.user', 'user'])
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('session.drawer', fn (Builder $query): Builder => $this->scopeBranch($query, $branchId, $user));

        $cashIn = (float) (clone $query)->where('type', 'cash_in')->sum('amount');
        $cashOut = (float) (clone $query)->where('type', 'cash_out')->sum('amount');
        $rows = (clone $query)->latest()->limit($limit)->get()->map(fn (DrawerMovement $movement): array => [
            'Date' => $movement->created_at?->format('d M Y H:i'),
            'Branch' => $movement->session?->drawer?->branch?->name ?? 'N/A',
            'Teller / Cashier' => $movement->session?->user?->name ?? $movement->user?->name ?? 'N/A',
            'Drawer' => $movement->session?->drawer?->name ?? 'N/A',
            'Reference' => $movement->reference ?? 'N/A',
            'Direction' => $movement->type === 'cash_out' ? 'Cash Out' : 'Cash In',
            'Amount' => $this->money((float) $movement->amount),
            'Balance After' => $this->money((float) $movement->balance_after),
            'Description' => $movement->description ?? '',
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
            ->when(! $branchId && ! $user->canPerform('manage-branches') && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id))
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
            ->when(! $branchId && ! $user->canPerform('manage-branches') && $user->branch_id, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id)->orWhereNull('branch_id')));

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

    private function accountingStatement(array $payload, CarbonInterface $from, CarbonInterface $to, ?int $branchId, User $user): array
    {
        $types = match ($payload['statement'] ?? 'trial') {
            'income' => ['income', 'expense'],
            'balance' => ['asset', 'liability', 'equity'],
            default => ['asset', 'liability', 'equity', 'income', 'expense'],
        };

        $rows = ChartAccount::query()
            ->whereIn('type', $types)
            ->withSum(['journalEntries as debit_total' => fn (Builder $query) => $query->where('entry_type', 'debit')->whereBetween(DB::raw('coalesce(posted_at, created_at)'), [$from, $to])], 'amount')
            ->withSum(['journalEntries as credit_total' => fn (Builder $query) => $query->where('entry_type', 'credit')->whereBetween(DB::raw('coalesce(posted_at, created_at)'), [$from, $to])], 'amount')
            ->when($branchId, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when(! $branchId && ! $user->canPerform('manage-branches') && $user->branch_id, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id)->orWhereNull('branch_id')))
            ->orderBy('code')
            ->get()
            ->map(fn (ChartAccount $account): array => [
                'Code' => $account->code,
                'Account' => $account->name,
                'Type' => Str::headline($account->type),
                'Debit' => $this->money((float) $account->debit_total),
                'Credit' => $this->money((float) $account->credit_total),
                'Net' => $this->money((float) $account->debit_total - (float) $account->credit_total),
            ]);

        return [['Code', 'Account', 'Type', 'Debit', 'Credit', 'Net'], $rows, ['Rows' => number_format($rows->count())]];
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
            ->when(! $branchId && ! $user->canPerform('manage-branches') && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id));

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
            ->whereIn('status', ['approved', 'disbursed', 'active', 'in_arrears', 'restructured', 'fully_paid'])
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
            $arrearsDays = (int) $overdueSchedules->max(fn (LoanSchedule $schedule): int => (int) ($schedule->days_overdue ?: max(0, $schedule->due_date?->diffInDays(today()) ?? 0)));
            $isArrears = $overdueSchedules->isNotEmpty() || $loan->status === 'in_arrears';
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
            'Days Due' => number_format((int) ($schedule->days_overdue ?: max(0, (int) $schedule->due_date?->diffInDays(now())))),
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
            ->when(! $branchId && ! $user->canPerform('manage-branches') && $user->branch_id, fn (Builder $query): Builder => $query->where('branch_id', $user->branch_id));

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

        if (! $user->canPerform('manage-branches') && $user->branch_id) {
            return $query->where('branch_id', $user->branch_id);
        }

        return $query;
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
