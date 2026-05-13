<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleResource;
use App\Models\Loan;
use App\Services\LoanApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoanController extends Controller
{
    public function index(Request $request): JsonResponse
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

        return ApiResponse::collection(LoanResource::collection($loans), 'Loans retrieved.');
    }

    public function store(StoreLoanApplicationRequest $request, LoanApplicationService $applications): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);

        return ApiResponse::resource(
            new LoanResource($applications->submit($request->validated(), $request->user())->load(['client', 'product'])),
            'Loan application created successfully.',
            201
        );
    }

    public function show(Loan $loan): JsonResponse
    {
        abort_unless(request()->user()->canPerform('manage-loans') || $loan->client_id === request()->user()->client?->id, 403);

        return ApiResponse::resource(new LoanResource($loan->load(['client', 'product', 'schedules', 'repayments.receivedBy'])), 'Loan retrieved.');
    }

    public function schedule(Loan $loan): JsonResponse
    {
        abort_unless(request()->user()->canPerform('manage-loans') || $loan->client_id === request()->user()->client?->id, 403);

        return ApiResponse::collection(
            LoanScheduleResource::collection($loan->schedules()->orderBy('installment_number')->paginate(request()->integer('per_page', 50))),
            'Loan repayment schedule retrieved.'
        );
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
