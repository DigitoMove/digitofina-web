<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsProductResource;
use App\Models\SavingsProduct;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;

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
}
