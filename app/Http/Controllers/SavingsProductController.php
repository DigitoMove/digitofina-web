<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingsProductRequest;
use App\Http\Requests\UpdateSavingsProductRequest;
use App\Models\SavingsProduct;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavingsProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = SavingsProduct::query()
            ->withCount('accounts')
            ->when($request->search, fn ($query, string $search) => $query->whereAny(['name', 'code'], 'like', "%{$search}%"))
            ->when($request->status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('savings-products.index', compact('products'));
    }

    public function create(): View
    {
        return view('savings-products.create', ['product' => new SavingsProduct([
            'interest_posting_frequency' => 'monthly',
            'account_number_prefix' => 'SAV',
            'status' => 'active',
        ])]);
    }

    public function store(StoreSavingsProductRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $product = SavingsProduct::create($request->validated() + [
            'organization_id' => $request->user()->organization_id,
            'allows_overdraft' => $request->boolean('allows_overdraft'),
        ]);

        $auditLogger->record('savings_product.created', $product, $product->toArray());

        return redirect()->route('savings-products.index')->with('status', 'Savings product created.');
    }

    public function edit(SavingsProduct $savingsProduct): View
    {
        return view('savings-products.edit', ['product' => $savingsProduct]);
    }

    public function update(UpdateSavingsProductRequest $request, SavingsProduct $savingsProduct, AuditLogger $auditLogger): RedirectResponse
    {
        $old = $savingsProduct->toArray();
        $savingsProduct->update($request->validated() + [
            'allows_overdraft' => $request->boolean('allows_overdraft'),
        ]);
        $auditLogger->record('savings_product.updated', $savingsProduct, $savingsProduct->fresh()->toArray(), $old);

        return redirect()->route('savings-products.index')->with('status', 'Savings product updated.');
    }
}
