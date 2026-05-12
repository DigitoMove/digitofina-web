<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsAccountRequest;
use App\Http\Resources\SavingsAccountResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\SavingsAccount;
use App\Services\SavingsAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SavingsAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-own-accounts'), 403);

        $accounts = SavingsAccount::query()
            ->with(['client', 'branch', 'product'])
            ->when($request->user()->isMember(), fn ($query) => $query->where('client_id', $request->user()->client?->id))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where('account_number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($query) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone'], 'like', "%{$search}%"));
            })
            ->when($request->integer('branch_id'), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($request->integer('savings_product_id'), fn ($query, int $productId) => $query->where('savings_product_id', $productId))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return SavingsAccountResource::collection($accounts);
    }

    public function store(StoreSavingsAccountRequest $request, SavingsAccountService $accounts): SavingsAccountResource
    {
        abort_unless($request->user()->canPerform('manage-accounts'), 403);

        return new SavingsAccountResource($accounts->open($request->validated(), $request->user())->load(['client', 'branch', 'product']));
    }

    public function show(SavingsAccount $savingsAccount): SavingsAccountResource
    {
        abort_unless(request()->user()->canPerform('manage-accounts') || $savingsAccount->client_id === request()->user()->client?->id, 403);

        return new SavingsAccountResource($savingsAccount->load(['client', 'branch', 'product']));
    }

    public function statement(Request $request, SavingsAccount $savingsAccount): AnonymousResourceCollection
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $savingsAccount->client_id === $request->user()->client?->id, 403);

        $transactions = $savingsAccount->transactions()
            ->with(['account', 'teller'])
            ->when($request->date_from, fn ($query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($request->date_to, fn ($query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->latest('transaction_date')
            ->paginate($request->integer('per_page', 30));

        return SavingsTransactionResource::collection($transactions);
    }

    public function update(Request $request, SavingsAccount $savingsAccount): Response
    {
        abort(405);
    }

    public function destroy(SavingsAccount $savingsAccount): Response
    {
        abort(405);
    }
}
