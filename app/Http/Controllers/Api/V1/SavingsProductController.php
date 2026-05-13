<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsProductResource;
use App\Models\SavingsProduct;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SavingsProductController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::collection(
            SavingsProductResource::collection(SavingsProduct::query()->where('status', 'active')->orderBy('name')->paginate(request()->integer('per_page', 20))),
            'Savings products retrieved.'
        );
    }
}
