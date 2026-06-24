<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanApplicationService;
use App\Services\LoanApprovalService;
use App\Services\LoanDisbursementService;
use App\Services\LoanScheduleService;
use App\Services\LoanService;
use App\Services\LoanVisibilityService;
use App\Support\CurrencyDenomination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

class LoanController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): View
    {
        Gate::authorize('viewAny', Loan::class);

        $loans = Loan::query()
            ->with(['client', 'product', 'officer'])
            ->tap(fn ($query) => $visibility->scope($query, $request->user()))
            ->tap(fn (Builder $query): Builder => $visibility->scopeOperationalOrCreated($query, $request->user()))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('loan_number', 'like', "%{$search}%")
                        ->orWhereHas('client', function ($query) use ($search): void {
                            $query->where('client_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('status_group')->toString() === 'applications', fn ($query) => $query->whereIn('status', ['under_review', 'submitted', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved']))
            ->when($request->string('loan_type')->toString(), fn ($query, string $loanType) => $query->where('loan_type', $loanType))
            ->when($request->integer('officer_id'), fn ($query, int $officerId) => $query->where('officer_id', $officerId))
            ->when($request->integer('loan_product_id'), fn ($query, int $productId) => $query->where('loan_product_id', $productId))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('loans.index', [
            'loans' => $loans,
            'products' => LoanProduct::orderBy('name')->get(),
            'officers' => $visibility->assignableOfficers($request->user())->get(),
        ]);
    }

    public function create(Request $request, LoanVisibilityService $visibility): View
    {
        Gate::authorize('create', Loan::class);

        return view('loans.create', [
            'clients' => $this->visibleClients($request)->where('status', 'active')->orderBy('first_name')->get(),
            'products' => LoanProduct::where('is_active', true)->where('status', 'active')->orderBy('name')->get(),
            'assignableOfficers' => $visibility->assignableOfficers($request->user())->get(),
            'canAssignOfficer' => $visibility->canAssignOfficer($request->user()),
            'selectedClient' => $request->integer('client_id'),
        ]);
    }

    public function store(StoreLoanApplicationRequest $request, LoanApplicationService $applications): RedirectResponse
    {
        $loan = $applications->submit($request->validated(), $request->user());

        return redirect()->route('loans.show', $loan)->with('status', 'Loan application created.');
    }

    public function schedulePreview(Request $request, LoanScheduleService $schedules): JsonResponse
    {
        Gate::authorize('create', Loan::class);

        $data = $request->validate([
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'application_date' => ['nullable', 'date', 'before_or_equal:today'],
            'requested_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'term_periods' => ['required', 'integer', 'min:1', 'max:730'],
            'repayment_frequency' => ['required', 'in:daily,weekly,monthly'],
        ]);

        $product = LoanProduct::query()->findOrFail($data['loan_product_id']);
        $principal = (float) $data['requested_amount'];
        $periods = (int) $data['term_periods'];
        $periodicRate = $this->periodicRate((float) $product->annual_interest_rate, $data['repayment_frequency']);
        $applicationDate = CarbonImmutable::parse($data['application_date'] ?? today()->toDateString());

        $schedule = collect($schedules->preview($principal, $periodicRate, $periods, $product->interest_method, $applicationDate, $data['repayment_frequency']))
            ->map(fn (array $row): array => [
                'installment' => $row['installment'],
                'due_date' => $row['due_date'],
                'principal' => round($row['principal'], 2),
                'interest' => round($row['interest'], 2),
                'total' => round($row['total'], 2),
                'balance' => round($row['balance'], 2),
            ]);

        return response()->json([
            'summary' => [
                'installments' => $periods,
                'principal' => $principal,
                'annual_interest_rate' => (float) $product->annual_interest_rate,
                'interest' => round($schedule->sum('interest'), 2),
                'total' => round($schedule->sum('total'), 2),
            ],
            'schedule' => $schedule->values(),
        ]);
    }

    public function show(Loan $loan, LoanVisibilityService $visibility, LoanService $service): View
    {
        Gate::authorize('view', $loan);
        $isRestrictedLoanRecoveryOfficer = request()->user()->isLoanRecoveryOfficer()
            && (int) $loan->created_by !== (int) request()->user()->id;

        if (! $loan->schedules()->exists() && (float) $loan->requested_amount > 0) {
            $service->generateSchedule($loan->load('product'), CarbonImmutable::parse(($loan->disbursed_at ?? $loan->application_date ?? today())->toDateString()));
        }

        return view('loans.show', [
            'loan' => $loan->load([
                'branch',
                'client.accounts.accountType',
                'client.branch',
                'client.documents',
                'creator',
                'officer',
                'product',
                'savingsAccount.accountType',
                'loanAccount.accountType',
                'schedules',
                'loanGuarantors.guarantorClient',
                'collaterals',
                'approvals.approver',
                'disbursements.disbursedBy',
                'repayments' => fn ($query) => $query
                    ->with('receivedBy')
                    ->orderByDesc('paid_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id'),
            ]),
            'guarantorClients' => $isRestrictedLoanRecoveryOfficer ? collect() : $this->visibleClients(request())->where('status', 'active')->where('id', '!=', $loan->client_id)->orderBy('first_name')->get(),
            'assignableOfficers' => $isRestrictedLoanRecoveryOfficer ? collect() : $visibility->assignableOfficers(request()->user())->get(),
            'canAssignOfficer' => ! $isRestrictedLoanRecoveryOfficer && $visibility->canAssignOfficer(request()->user()),
            'products' => $isRestrictedLoanRecoveryOfficer ? collect() : LoanProduct::where('is_active', true)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    /**
     * @return Builder<Client>
     */
    private function visibleClients(Request $request): Builder
    {
        return Client::query()
            ->when($request->user()->isLoanOfficer(), fn ($query) => $query
                ->where('branch_id', $request->user()->branch_id)
                ->where(function (Builder $query) use ($request): void {
                    $query->where('officer_id', $request->user()->id)
                        ->orWhere(function (Builder $query) use ($request): void {
                            $query->whereNull('officer_id')
                                ->whereExists(function ($query) use ($request): void {
                                    $query->selectRaw('1')
                                        ->from('loans')
                                        ->whereColumn('loans.client_id', 'clients.id')
                                        ->where('loans.officer_id', $request->user()->id)
                                        ->whereRaw('loans.id = (
                                            select recent_loans.id
                                            from loans as recent_loans
                                            where recent_loans.client_id = clients.id
                                                and recent_loans.officer_id is not null
                                            order by recent_loans.application_date desc, recent_loans.id desc
                                            limit 1
                                        )');
                                });
                        });
                }));
    }

    public function updateDraftTerms(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless(
            $request->user()->hasRole('super-admin')
                || $request->user()->canPerform('manage-users')
                || $request->user()->canPerform('disburse-loans')
                || $request->user()->canManageCreatedLoanApplication($loan),
            403
        );

        $data = $request->validate([
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'requested_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'term_periods' => ['required', 'integer', 'min:1'],
            'application_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            $service->updateDraftTerms($loan, $request->user(), $data);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['draft_terms' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Draft loan terms updated and schedule recalculated.');
    }

    public function repairTerms(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless($request->user()->canAdministerLoanRepairs(), 403);

        $data = $request->validate([
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'approved_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'term_periods' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['required', 'in:daily,weekly,bi-weekly,monthly'],
            'schedule_start_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $service->repairTermsAndSchedule($loan, $request->user(), $data);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['repair_terms' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan terms repaired and repayment schedule recalculated.');
    }

    public function generateSavingsAccount(Loan $loan, LoanService $service): RedirectResponse
    {
        $service->generateSavingsAccount($loan, auth()->user());

        return back()->with('status', 'Savings account generated.');
    }

    public function generateLoanAccount(Loan $loan, LoanService $service): RedirectResponse
    {
        $service->generateLoanAccount($loan, auth()->user());

        return back()->with('status', 'Loan account generated.');
    }

    public function submitForAppraisal(Loan $loan, LoanService $service): RedirectResponse
    {
        try {
            $service->submitForAppraisal($loan, auth()->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Loan submitted for appraisal.');
    }

    public function submitForApproval(Loan $loan, LoanService $service): RedirectResponse
    {
        try {
            $service->submitForApproval($loan, auth()->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Loan submitted for approval.');
    }

    public function approve(ApproveLoanRequest $request, Loan $loan, LoanApprovalService $approvals): RedirectResponse
    {
        $data = $request->validated();

        try {
            $approvals->approve($loan, $request->user(), (float) $data['approved_amount'], $data['comments'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['approved_amount' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan approved.');
    }

    public function fastTrackDisbursement(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless(
            $request->user()->canPerform('manage-loans')
            && $request->user()->canPerform('approve-loans')
            && $request->user()->canPerform('disburse-loans'),
            403
        );

        $data = $request->validate([
            'approved_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'comments' => ['nullable', 'string'],
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        try {
            $service->fastTrackDisbursement($loan, $request->user(), (float) $data['approved_amount'], $data['payment_method'], $data['comments'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['fast_track' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan appraised, approved, and disbursed.');
    }

    public function reject(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('approve-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);
        try {
            $service->reject($loan, auth()->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan rejected.');
    }

    public function cancel(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin') || $request->user()->canPerform('manage-users') || $request->user()->canPerform('disburse-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $service->cancel($loan, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['cancel_reason' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan cancelled.');
    }

    public function closePaid(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless($request->user()->canAdministerLoanRepairs(), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $service->closeFullyPaidLoan($loan, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['close_paid_reason' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Fully paid loan closed.');
    }

    public function closeWithCoverage(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless($request->user()->canAdministerLoanRepairs(), 403);

        $data = $request->validate([
            'coverage_method' => ['required', 'in:organization_covered,write_off,external_settlement'],
            'coverage_reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        abort_unless($data['coverage_method'] !== 'write_off' || $request->user()->canWriteOffLoans(), 403);

        try {
            $service->closeWithBalanceCoverage(
                $loan,
                $request->user(),
                $data['coverage_method'],
                $data['reason'],
                $data['coverage_reference'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['coverage_reason' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan closed with balance coverage recorded.');
    }

    public function reopen(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless($request->user()->canAdministerLoanRepairs(), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $loan->loadMissing('loanAccount');
        $reactivatingLoanAccount = in_array($loan->status, ['disbursed', 'active', 'in_arrears'], true)
            && $loan->loanAccount
            && $loan->loanAccount->status !== 'active';

        try {
            $service->reopenAndClearPaidInstallments($loan, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['reopen_reason' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', $reactivatingLoanAccount ? 'Loan account reactivated.' : 'Loan reopened and paid installments cleared.');
    }

    public function assignOfficer(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless($request->user()->hasRole('super-admin') || $request->user()->canPerform('manage-users') || $request->user()->canPerform('disburse-loans'), 403);

        $data = $request->validate([
            'officer_id' => ['required', 'exists:users,id'],
        ]);

        try {
            $service->assignOfficer($loan, $request->user(), (int) $data['officer_id']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['officer_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Assigned officer updated.');
    }

    public function disburse(Request $request, Loan $loan, LoanDisbursementService $disbursements): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('disburse-loans'), 403);

        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        try {
            $disbursements->disburseToSavings($loan, $request->user(), $data['payment_method']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['payment_method' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Loan disbursed into client savings account.');
    }

    private function periodicRate(float $annualRate, string $frequency): float
    {
        return match ($frequency) {
            'daily' => $annualRate / 365,
            'weekly' => $annualRate / 52,
            default => $annualRate / 12,
        };
    }
}
