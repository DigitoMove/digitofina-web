<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanProductRequest;
use App\Http\Requests\UpdateLoanProductRequest;
use App\Models\LoanProduct;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoanProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = LoanProduct::query()
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('loan-products.index', ['products' => $products]);
    }

    public function create(): View
    {
        return view('loan-products.create');
    }

    public function store(StoreLoanProductRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $product = LoanProduct::create($this->payload($request->validated()));

        $auditLogger->record('loan_product.created', $product, $product->toArray());

        return redirect()->route('loan-products.index')->with('status', 'Loan product created.');
    }

    public function edit(LoanProduct $loanProduct): View
    {
        return view('loan-products.edit', ['product' => $loanProduct]);
    }

    public function update(UpdateLoanProductRequest $request, LoanProduct $loanProduct, AuditLogger $auditLogger): RedirectResponse
    {
        $old = $loanProduct->toArray();
        $loanProduct->update($this->payload($request->validated()));
        $auditLogger->record('loan_product.updated', $loanProduct, $loanProduct->fresh()->toArray(), $old);

        return redirect()->route('loan-products.index')->with('status', 'Loan product updated.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return array_merge($validated, [
            'organization_id' => auth()->user()->organization_id,
            'is_active' => ($validated['status'] ?? 'active') === 'active',
            'require_guarantors' => request()->boolean('require_guarantors'),
            'require_collateral' => request()->boolean('require_collateral'),
            'auto_deduct_repayment_from_savings' => request()->boolean('auto_deduct_repayment_from_savings'),
        ]);
    }
}
