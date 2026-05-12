<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LoanProductResource::collection(
            LoanProduct::query()->where('status', 'active')->orderBy('name')->paginate(request()->integer('per_page', 20))
        );
    }
}
