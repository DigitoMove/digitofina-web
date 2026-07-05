<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanProductRequest;
use App\Http\Requests\UpdateLoanProductRequest;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoanProductController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canManageLoanProducts = $this->canManageLoanProducts($user);

        abort_unless($canManageLoanProducts || $this->canViewLoanProductsReference($user), 403);

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

        return view('loan-products.index', [
            'products' => $products,
            'canManageLoanProducts' => $canManageLoanProducts,
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->canManageLoanProducts($request->user()), 403);

        return view('loan-products.create');
    }

    public function store(StoreLoanProductRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageLoanProducts($request->user()), 403);

        $product = LoanProduct::create($this->payload($request->validated()));

        $auditLogger->record('loan_product.created', $product, $product->toArray());

        return redirect()->route('loan-products.index')->with('status', 'Loan product created.');
    }

    public function edit(Request $request, LoanProduct $loanProduct): View
    {
        abort_unless($this->canManageLoanProducts($request->user()), 403);

        return view('loan-products.edit', ['product' => $loanProduct]);
    }

    public function update(UpdateLoanProductRequest $request, LoanProduct $loanProduct, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($this->canManageLoanProducts($request->user()), 403);

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

    private function canViewLoanProductsReference(User $user): bool
    {
        return $user->isTellerCashier()
            || $user->isLoanOfficer()
            || $user->isLoanRecoveryOfficer();
    }

    private function canManageLoanProducts(User $user): bool
    {
        return ! $this->canViewLoanProductsReference($user)
            && $user->canPerform('manage-loans');
    }
}
