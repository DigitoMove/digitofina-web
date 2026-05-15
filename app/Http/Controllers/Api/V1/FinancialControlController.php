<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Models\MakerCheckerRequest;
use App\Services\FinancialControlService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FinancialControlController extends Controller
{
    public function approvals(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('view-approvals') || $request->user()->canPerform('approve-maker-checker'), 403);

        $approvals = ApiQuery::apply(
            ApiSecurity::scopeTenant(MakerCheckerRequest::query(), $request->user())->with(['requestedBy', 'checkedBy', 'branch']),
            $request,
            ['action', 'reason'],
            ['action', 'status', 'branch_id'],
            ['id', 'action', 'status', 'amount', 'created_at', 'updated_at'],
        )->paginate(ApiQuery::perPage($request, 30));

        return ApiResponse::collection(MakerCheckerRequestResource::collection($approvals), 'Maker-checker requests retrieved.', meta: ApiQuery::meta($request));
    }

    public function approve(Request $request, MakerCheckerRequest $makerCheckerRequest, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $makerCheckerRequest);
        abort_unless($request->user()->canPerform('approve-maker-checker') || $request->user()->canPerform('override-maker-checker') || ($makerCheckerRequest->required_permission && $request->user()->canPerform($makerCheckerRequest->required_permission)), 403);

        $data = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approval = $controls->approveMakerChecker($makerCheckerRequest, $request->user(), $data['comments'] ?? null);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'checkedBy', 'branch'])), 'Maker-checker request approved.');
    }

    public function reject(Request $request, MakerCheckerRequest $makerCheckerRequest, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $makerCheckerRequest);
        abort_unless($request->user()->canPerform('approve-maker-checker') || $request->user()->canPerform('override-maker-checker') || ($makerCheckerRequest->required_permission && $request->user()->canPerform($makerCheckerRequest->required_permission)), 403);

        $data = $request->validate([
            'comments' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $approval = $controls->rejectMakerChecker($makerCheckerRequest, $request->user(), $data['comments']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'checkedBy', 'branch'])), 'Maker-checker request rejected.');
    }
}
