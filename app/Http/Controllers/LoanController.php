<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Models\Account;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\AccountNumberGenerator;
use App\Services\AuditLogger;
use App\Services\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoanController extends Controller
{
    public function index(Request $request): View
    {
        $loans = Loan::query()
            ->with(['client', 'product', 'officer'])
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($query) use ($search): void {
                        $query->where('client_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->integer('loan_product_id'), fn ($query, int $productId) => $query->where('loan_product_id', $productId))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('loans.index', [
            'loans' => $loans,
            'products' => LoanProduct::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('loans.create', [
            'clients' => Client::where('status', 'active')->orderBy('first_name')->get(),
            'products' => LoanProduct::where('is_active', true)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreLoanApplicationRequest $request, AccountNumberGenerator $numbers, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validated();
        $client = Client::with('branch')->findOrFail($data['client_id']);
        $product = LoanProduct::findOrFail($data['loan_product_id']);
        $savings = Account::where('client_id', $client->id)->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->first();
        $requestedAmount = (float) $data['requested_amount'];

        if ($requestedAmount < (float) $product->min_amount || $requestedAmount > (float) $product->max_amount) {
            return back()->withErrors(['requested_amount' => 'Requested amount is outside the selected product limits.'])->withInput();
        }

        if ((int) $data['term_periods'] < (int) $product->min_term || (int) $data['term_periods'] > (int) $product->max_term) {
            return back()->withErrors(['term_periods' => 'Loan term is outside the selected product limits.'])->withInput();
        }

        $savingsBalance = (float) ($savings?->balance ?? 0);
        $requiredSavings = round($requestedAmount * ((float) $product->required_savings_percentage / 100), 2);

        $status = match (true) {
            $product->require_guarantors => 'pending_guarantor_approval',
            $product->require_collateral => 'pending_collateral_verification',
            default => 'submitted',
        };

        $loan = Loan::create($data + [
            'organization_id' => auth()->user()->organization_id,
            'branch_id' => $client->branch_id,
            'officer_id' => auth()->id(),
            'savings_account_id' => $savings?->id,
            'loan_number' => $numbers->loanNumber($client->branch),
            'interest_rate' => $product->annual_interest_rate,
            'savings_balance_at_application' => $savingsBalance,
            'required_savings_amount' => $requiredSavings,
            'application_date' => now()->toDateString(),
            'status' => $status,
            'submitted_at' => now()->toDateString(),
        ]);

        $auditLogger->record('loan.submitted', $loan, $loan->toArray());

        return redirect()->route('loans.show', $loan)->with('status', 'Loan application submitted.');
    }

    public function show(Loan $loan): View
    {
        return view('loans.show', [
            'loan' => $loan->load([
                'client.accounts.accountType',
                'product',
                'schedules',
                'guarantors.guarantorClient',
                'collaterals',
                'approvals.approver',
                'disbursements.disbursedBy',
                'repayments.receivedBy',
            ]),
            'guarantorClients' => Client::where('status', 'active')->where('id', '!=', $loan->client_id)->orderBy('first_name')->get(),
        ]);
    }

    public function approve(ApproveLoanRequest $request, Loan $loan, LoanService $service): RedirectResponse
    {
        $data = $request->validated();

        $service->approve($loan, auth()->user(), (float) $data['approved_amount'], $data['comments'] ?? null);

        return back()->with('status', 'Loan approved.');
    }

    public function reject(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('approve-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);
        $service->reject($loan, auth()->user(), $data['reason']);

        return back()->with('status', 'Loan rejected.');
    }

    public function disburse(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('disburse-loans') || auth()->user()->canPerform('manage-loans'), 403);

        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        $service->disburseToSavings($loan, auth()->user(), $data['payment_method']);

        return back()->with('status', 'Loan disbursed into client savings account.');
    }
}
