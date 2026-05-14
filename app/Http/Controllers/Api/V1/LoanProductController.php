<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;

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
}
