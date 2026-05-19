<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvestmentPlanRequest;
use App\Http\Resources\InvestmentPlanResource;
use App\Models\InvestmentPlan;
use App\Services\InvestmentService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestmentPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports'), 403);

        $plans = ApiSecurity::scopeTenant(InvestmentPlan::query(), $request->user())
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::collection(InvestmentPlanResource::collection($plans), 'Investment plans retrieved.');
    }

    public function store(StoreInvestmentPlanRequest $request, InvestmentService $investments): JsonResponse
    {
        $plan = $investments->createPlan($request->validated(), $request->user());

        return ApiResponse::resource(new InvestmentPlanResource($plan), 'Investment plan created.', 201);
    }

    public function show(Request $request, InvestmentPlan $investmentPlan): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investmentPlan);
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports'), 403);

        return ApiResponse::resource(new InvestmentPlanResource($investmentPlan), 'Investment plan retrieved.');
    }
}
