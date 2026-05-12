<?php

namespace App\Http\Controllers;

use App\Models\LoanProduct;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoanProductController extends Controller
{
    public function index(): View
    {
        return view('loan-products.index', ['products' => LoanProduct::latest()->paginate(15)]);
    }

    public function create(): View
    {
        return view('loan-products.create');
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $product = LoanProduct::create($request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:30', 'unique:loan_products,code'],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['required', 'numeric', 'gte:min_amount'],
            'annual_interest_rate' => ['required', 'numeric', 'min:0'],
            'interest_method' => ['required', 'in:reducing_balance,flat'],
            'repayment_frequency' => ['required', 'in:daily,weekly,monthly'],
            'default_term_months' => ['required', 'integer', 'min:1'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
        ]) + ['organization_id' => auth()->user()->organization_id, 'is_active' => true]);

        $auditLogger->record('loan_product.created', $product, $product->toArray());

        return redirect()->route('loan-products.index')->with('status', 'Loan product created.');
    }
}
