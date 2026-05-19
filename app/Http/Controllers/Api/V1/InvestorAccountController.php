<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvestorAccountRequest;
use App\Http\Resources\InvestorAccountResource;
use App\Models\Client;
use App\Models\InvestorAccount;
use App\Services\InvestmentService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class InvestorAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports'), 403);

        $accounts = ApiSecurity::scopeTenant(InvestorAccount::query()->with(['client', 'branch', 'assignedOfficer']), $request->user())
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::collection(InvestorAccountResource::collection($accounts), 'Investor accounts retrieved.');
    }

    public function store(StoreInvestorAccountRequest $request, InvestmentService $investments): JsonResponse
    {
        $client = Client::findOrFail($request->validated('client_id'));
        ApiSecurity::assertTenantAccess($request->user(), $client);

        try {
            $account = $investments->createInvestorAccount($request->validated(), $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new InvestorAccountResource($account->load(['client', 'branch', 'assignedOfficer'])), 'Investor account created.', 201);
    }

    public function show(Request $request, InvestorAccount $investorAccount): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $investorAccount);
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('manage-investments') || $request->user()->canPerform('view-reports') || $investorAccount->client_id === $request->user()->client?->id, 403);

        return ApiResponse::resource(new InvestorAccountResource($investorAccount->load(['client', 'branch', 'assignedOfficer'])), 'Investor account retrieved.');
    }
}
