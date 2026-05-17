<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\BusinessCalendarService;
use App\Services\LoanApplicationService;
use App\Services\LoanApprovalService;
use App\Services\LoanCalculatorService;
use App\Services\LoanDisbursementService;
use App\Services\LoanService;
use App\Services\LoanVisibilityService;
use App\Support\CurrencyDenomination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class LoanController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): View
    {
        $loans = Loan::query()
            ->with(['client', 'product', 'officer'])
            ->tap(fn ($query) => $visibility->scope($query, $request->user()))
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
            ->when($request->string('status_group')->toString() === 'applications', fn ($query) => $query->whereIn('status', ['draft', 'under_review', 'submitted', 'pending_guarantor_approval', 'pending_collateral_verification', 'approved']))
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
        return view('loans.create', [
            'clients' => Client::where('status', 'active')->orderBy('first_name')->get(),
            'products' => LoanProduct::where('is_active', true)->where('status', 'active')->orderBy('name')->get(),
            'assignableOfficers' => $visibility->assignableOfficers($request->user())->get(),
            'canAssignOfficer' => $visibility->canAssignOfficer($request->user()),
        ]);
    }

    public function store(StoreLoanApplicationRequest $request, LoanApplicationService $applications): RedirectResponse
    {
        $loan = $applications->submit($request->validated(), $request->user());

        return redirect()->route('loans.show', $loan)->with('status', 'Loan application created.');
    }

    public function schedulePreview(Request $request, LoanCalculatorService $calculator, BusinessCalendarService $calendar): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);

        $data = $request->validate([
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'requested_amount' => ['required', 'numeric', 'min:'.CurrencyDenomination::MINIMUM_UNIT, CurrencyDenomination::validationRule()],
            'term_periods' => ['required', 'integer', 'min:1', 'max:730'],
            'repayment_frequency' => ['required', 'in:daily,weekly,monthly'],
        ]);

        $product = LoanProduct::query()->findOrFail($data['loan_product_id']);
        $principal = (float) $data['requested_amount'];
        $periods = (int) $data['term_periods'];
        $periodicRate = $this->periodicRate((float) $product->annual_interest_rate, $data['repayment_frequency']);

        $rows = match ($product->interest_method) {
            'flat' => $calculator->flatRate($principal, $periodicRate, $periods),
            'reducing_balance_equal_installments' => $calculator->reducingBalanceEqualInstallments($principal, $periodicRate, $periods),
            default => $calculator->decliningBalance($principal, $periodicRate, $periods),
        };

        $schedule = collect($rows)->map(function (array $row) use ($calendar, $data): array {
            $dueDate = $calendar->dueDate(today(), $row['installment'], $data['repayment_frequency']);

            return [
                'installment' => $row['installment'],
                'due_date' => $dueDate->toDateString(),
                'principal' => round($row['principal'], 2),
                'interest' => round($row['interest'], 2),
                'total' => round($row['total'], 2),
                'balance' => round($row['balance'], 2),
            ];
        });

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

    public function show(Loan $loan, LoanVisibilityService $visibility): View
    {
        return view('loans.show', [
            'loan' => $loan->load([
                'client.accounts.accountType',
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
                'repayments.receivedBy',
            ]),
            'guarantorClients' => Client::where('status', 'active')->where('id', '!=', $loan->client_id)->orderBy('first_name')->get(),
            'assignableOfficers' => $visibility->assignableOfficers(request()->user())->get(),
            'canAssignOfficer' => $visibility->canAssignOfficer(request()->user()),
        ]);
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
