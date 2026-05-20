<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupSavingsAccountResource;
use App\Http\Resources\GroupSavingsContributionResource;
use App\Models\GroupSavingsAccount;
use App\Services\GroupSavingsService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class GroupSavingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-reports'), 403);

        $accounts = ApiSecurity::scopeTenant(GroupSavingsAccount::query()->with('clientGroup'), $request->user())
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::collection(GroupSavingsAccountResource::collection($accounts), 'Group savings accounts retrieved.');
    }

    public function store(Request $request, GroupSavingsService $groupSavings): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts'), 403);
        $data = $request->validate([
            'name' => ['required_without:client_group_id', 'nullable', 'string', 'max:255'],
            'client_group_id' => ['nullable', 'exists:client_groups,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'savings_account_id' => ['nullable', 'exists:savings_accounts,id'],
            'contribution_amount' => ['required', 'numeric', 'min:0'],
            'contribution_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'annually'])],
            'meeting_day' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'closed'])],
        ]);

        $account = $groupSavings->create($data, $request->user());

        return ApiResponse::resource(new GroupSavingsAccountResource($account->load('clientGroup')), 'Group savings account created.', 201);
    }

    public function contribute(Request $request, GroupSavingsAccount $groupSavingsAccount, GroupSavingsService $groupSavings): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $groupSavingsAccount);
        abort_unless($request->user()->canPerform('post-transactions'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,mobile_money,bank,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $contribution = $groupSavings->contribute($groupSavingsAccount, (int) $data['client_id'], (float) $data['amount'], $request->user(), $data['payment_method'], $data['narration'] ?? null);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new GroupSavingsContributionResource($contribution->load('client')), 'Group savings contribution posted.', 201);
    }
}
