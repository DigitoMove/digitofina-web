<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Http\Resources\SavingsProductResource;
use App\Models\SavingsProduct;
use App\Services\FinancialControlService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavingsProductController extends Controller
{
    public function index(): JsonResponse
    {
        $query = ApiSecurity::scopeTenant(SavingsProduct::query(), request()->user())
            ->where('status', 'active');

        return ApiResponse::collection(
            SavingsProductResource::collection(ApiQuery::apply(
                $query,
                request(),
                ['name', 'code'],
                ['status'],
                ['id', 'name', 'code', 'status', 'created_at', 'updated_at'],
                'name',
                'asc',
            )->paginate(ApiQuery::perPage(request(), 20))),
            'Savings products retrieved.',
            meta: ApiQuery::meta(request())
        );
    }

    public function update(Request $request, SavingsProduct $savingsProduct, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsProduct);
        ApiSecurity::assertPermission($request->user(), 'manage-accounts');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('savings_products', 'code')->ignore($savingsProduct)],
            'interest_rate' => ['sometimes', 'numeric', 'min:0'],
            'withdrawal_charge' => ['sometimes', 'numeric', 'min:0'],
            'deposit_charge' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $action = array_key_exists('interest_rate', $data) && (float) $data['interest_rate'] !== (float) $savingsProduct->interest_rate
            ? 'interest_rate_change'
            : 'savings_product_update';

        if ($controls->requiresMakerChecker($request->user(), $action)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: $action,
                subject: $savingsProduct,
                payload: $data,
                amount: null,
                requiredPermission: 'manage-accounts',
                reason: 'Savings product change requires checker approval.',
                oldValues: $savingsProduct->only(array_keys($data)),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Savings product update submitted for maker-checker review.', 202);
        }

        $savingsProduct->update($data + ['updated_by' => $request->user()->id]);

        return ApiResponse::resource(new SavingsProductResource($savingsProduct->fresh()), 'Savings product updated successfully.');
    }
}
