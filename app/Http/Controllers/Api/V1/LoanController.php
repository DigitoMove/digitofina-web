<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleResource;
use App\Models\Client;
use App\Models\Loan;
use App\Services\LoanApplicationService;
use App\Services\LoanVisibilityService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoanController extends Controller
{
    public function index(Request $request, LoanVisibilityService $visibility): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('view-own-accounts'), 403);

        $loansQuery = ApiSecurity::scopeTenant(Loan::query(), $request->user())
            ->with(['client', 'product', 'officer'])
            ->forOperationalListing()
            ->tap(fn ($query) => $request->user()->isMember() ? $query : $visibility->scope($query, $request->user()))
            ->when($request->user()->isMember(), fn ($query) => $query->where('client_id', $request->user()->client?->id))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('loan_number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($query) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone'], 'like', "%{$search}%"));
                });
            });

        $loans = ApiQuery::apply(
            $loansQuery,
            $request,
            ['purpose'],
            ['status', 'loan_product_id', 'client_id', 'loan_type', 'officer_id'],
            ['id', 'loan_number', 'requested_amount', 'approved_amount', 'status', 'application_date', 'created_at', 'updated_at'],
        )->paginate(ApiQuery::perPage($request, 15));

        return ApiResponse::collection(LoanResource::collection($loans), 'Loans retrieved.', meta: ApiQuery::meta($request));
    }

    public function store(StoreLoanApplicationRequest $request, LoanApplicationService $applications): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        ApiSecurity::assertTenantAccess($request->user(), Client::findOrFail($request->validated('client_id')));

        return ApiResponse::resource(
            new LoanResource($applications->submit($request->validated(), $request->user())->load(['client', 'product', 'officer'])),
            'Loan application created successfully.',
            201
        );
    }

    public function show(Loan $loan): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $loan);
        abort_unless(request()->user()->canPerform('manage-loans') || $loan->client_id === request()->user()->client?->id, 403);

        return ApiResponse::resource(new LoanResource($loan->load(['client', 'product', 'officer', 'creator', 'schedules', 'repayments.receivedBy'])), 'Loan retrieved.');
    }

    public function schedule(Loan $loan): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $loan);
        abort_unless(request()->user()->canPerform('manage-loans') || $loan->client_id === request()->user()->client?->id, 403);

        return ApiResponse::collection(
            LoanScheduleResource::collection(ApiQuery::apply(
                $loan->schedules()->getQuery(),
                request(),
                [],
                ['status'],
                ['id', 'installment_number', 'due_date', 'status', 'created_at', 'updated_at'],
                'installment_number',
                'asc',
            )->paginate(ApiQuery::perPage(request(), 50))),
            'Loan repayment schedule retrieved.',
            meta: ApiQuery::meta(request())
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
