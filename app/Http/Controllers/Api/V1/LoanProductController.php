<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LoanProductController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::collection(
            LoanProductResource::collection(LoanProduct::query()->where('status', 'active')->orderBy('name')->paginate(request()->integer('per_page', 20))),
            'Loan products retrieved.'
        );
    }
}
