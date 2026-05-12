<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanApplicationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LoanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('view-own-accounts'), 403);

        $loans = Loan::query()
            ->with(['client', 'product'])
            ->when($request->user()->isMember(), fn ($query) => $query->where('client_id', $request->user()->client?->id))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($query) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone'], 'like', "%{$search}%"));
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->integer('loan_product_id'), fn ($query, int $productId) => $query->where('loan_product_id', $productId))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return LoanResource::collection($loans);
    }

    public function store(StoreLoanApplicationRequest $request, LoanApplicationService $applications): LoanResource
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);

        return new LoanResource($applications->submit($request->validated(), $request->user())->load(['client', 'product']));
    }

    public function show(Loan $loan): LoanResource
    {
        abort_unless(request()->user()->canPerform('manage-loans') || $loan->client_id === request()->user()->client?->id, 403);

        return new LoanResource($loan->load(['client', 'product', 'schedules', 'repayments.receivedBy']));
    }

    public function update(Request $request, Loan $loan): Response
    {
        abort(405);
    }

    public function destroy(Loan $loan): Response
    {
        abort(405);
    }
}
