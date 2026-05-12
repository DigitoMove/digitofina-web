<?php

namespace App\Http\Controllers;

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
    public function index(): View
    {
        return view('loans.index', ['loans' => Loan::with(['client', 'product'])->latest()->paginate(15)]);
    }

    public function create(): View
    {
        return view('loans.create', [
            'clients' => Client::where('status', 'active')->orderBy('first_name')->get(),
            'products' => LoanProduct::where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request, AccountNumberGenerator $numbers, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'requested_amount' => ['required', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:191'],
            'term_periods' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['required', 'in:daily,weekly,monthly'],
            'collateral_details' => ['nullable', 'string'],
            'credit_officer_comments' => ['nullable', 'string'],
        ]);

        $client = Client::with('branch')->findOrFail($data['client_id']);
        $product = LoanProduct::findOrFail($data['loan_product_id']);
        $savings = Account::where('client_id', $client->id)->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->first();

        $loan = Loan::create($data + [
            'organization_id' => auth()->user()->organization_id,
            'branch_id' => $client->branch_id,
            'officer_id' => auth()->id(),
            'savings_account_id' => $savings?->id,
            'loan_number' => $numbers->loanNumber($client->branch),
            'interest_rate' => $product->annual_interest_rate,
            'status' => 'submitted',
            'submitted_at' => now()->toDateString(),
        ]);

        $auditLogger->record('loan.submitted', $loan, $loan->toArray());

        return redirect()->route('loans.show', $loan)->with('status', 'Loan application submitted.');
    }

    public function show(Loan $loan): View
    {
        return view('loans.show', ['loan' => $loan->load(['client.accounts.accountType', 'product', 'schedules'])]);
    }

    public function approve(Request $request, Loan $loan, LoanService $service): RedirectResponse
    {
        $data = $request->validate([
            'approved_amount' => ['required', 'numeric', 'min:1'],
            'comments' => ['nullable', 'string'],
        ]);

        $service->approve($loan, auth()->user(), (float) $data['approved_amount'], $data['comments'] ?? null);

        return back()->with('status', 'Loan approved.');
    }

    public function disburse(Loan $loan, LoanService $service): RedirectResponse
    {
        $service->disburseToSavings($loan, auth()->user());

        return back()->with('status', 'Loan disbursed into client savings account.');
    }
}
