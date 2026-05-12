<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsProductResource;
use App\Models\SavingsProduct;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SavingsProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SavingsProductResource::collection(
            SavingsProduct::query()->where('status', 'active')->orderBy('name')->paginate(request()->integer('per_page', 20))
        );
    }
}
