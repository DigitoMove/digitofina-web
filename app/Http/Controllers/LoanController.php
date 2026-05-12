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

    public function store(StoreLoanApplicationRequest $request, LoanApplicationService $applications): RedirectResponse
    {
        $loan = $applications->submit($request->validated(), $request->user());

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

    public function approve(ApproveLoanRequest $request, Loan $loan, LoanApprovalService $approvals): RedirectResponse
    {
        $data = $request->validated();

        $approvals->approve($loan, $request->user(), (float) $data['approved_amount'], $data['comments'] ?? null);

        return back()->with('status', 'Loan approved.');
    }

    public function reject(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('approve-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);
        $service->reject($loan, auth()->user(), $data['reason']);

        return back()->with('status', 'Loan rejected.');
    }

    public function disburse(Request $request, Loan $loan, LoanDisbursementService $disbursements): RedirectResponse
    {
        abort_unless(auth()->user()->canPerform('disburse-loans') || auth()->user()->canPerform('manage-loans'), 403);

        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        $disbursements->disburseToSavings($loan, $request->user(), $data['payment_method']);

        return back()->with('status', 'Loan disbursed into client savings account.');
    }
}
