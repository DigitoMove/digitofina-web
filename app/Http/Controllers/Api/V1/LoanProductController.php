<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanProductResource;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Models\LoanProduct;
use App\Services\FinancialControlService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanProductController extends Controller
{
    public function index(): JsonResponse
    {
        $query = ApiSecurity::scopeTenant(LoanProduct::query(), request()->user())
            ->where('status', 'active');

        return ApiResponse::collection(
            LoanProductResource::collection(ApiQuery::apply(
                $query,
                request(),
                ['name', 'code'],
                ['status', 'interest_method', 'repayment_frequency'],
                ['id', 'name', 'code', 'status', 'created_at', 'updated_at'],
                'name',
                'asc',
            )->paginate(ApiQuery::perPage(request(), 20))),
            'Loan products retrieved.',
            meta: ApiQuery::meta(request())
        );
    }

    public function update(Request $request, LoanProduct $loanProduct, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loanProduct);
        ApiSecurity::assertPermission($request->user(), 'manage-loans');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('loan_products', 'code')->ignore($loanProduct)],
            'annual_interest_rate' => ['sometimes', 'numeric', 'min:0'],
            'penalty_rate' => ['sometimes', 'numeric', 'min:0'],
            'processing_fee' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $action = array_key_exists('annual_interest_rate', $data) && (float) $data['annual_interest_rate'] !== (float) $loanProduct->annual_interest_rate
            ? 'interest_rate_change'
            : 'loan_product_update';

        if ($controls->requiresMakerChecker($request->user(), $action)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: $action,
                subject: $loanProduct,
                payload: $data,
                amount: null,
                requiredPermission: 'manage-loans',
                reason: 'Loan product change requires checker approval.',
                oldValues: $loanProduct->only(array_keys($data)),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Loan product update submitted for maker-checker review.', 202);
        }

        $loanProduct->update($data + ['updated_by' => $request->user()->id]);

        return ApiResponse::resource(new LoanProductResource($loanProduct->fresh()), 'Loan product updated successfully.');
    }
}
