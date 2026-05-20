<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsGoalResource;
use App\Http\Resources\SavingsGoalTransactionResource;
use App\Models\SavingsGoal;
use App\Services\SavingsGoalService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SavingsGoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-reports') || $request->user()->canPerform('view-own-accounts'), 403);

        $goals = ApiSecurity::scopeTenant(SavingsGoal::query()->with(['client', 'linkedSavingsAccount']), $request->user())
            ->when($request->user()->isMember(), fn ($query) => $query->where('client_id', $request->user()->client?->id))
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::collection(SavingsGoalResource::collection($goals), 'Savings goals retrieved.');
    }

    public function store(Request $request, SavingsGoalService $goals): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-own-accounts'), 403);
        $data = $this->goalData($request);

        try {
            $goal = $goals->create($data, $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new SavingsGoalResource($goal->load(['client', 'linkedSavingsAccount'])), 'Savings goal created.', 201);
    }

    public function show(Request $request, SavingsGoal $savingsGoal): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsGoal);
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-reports') || $savingsGoal->client_id === $request->user()->client?->id, 403);

        return ApiResponse::resource(new SavingsGoalResource($savingsGoal->load(['client', 'linkedSavingsAccount'])), 'Savings goal retrieved.');
    }

    public function transact(Request $request, SavingsGoal $savingsGoal, SavingsGoalService $goals): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsGoal);
        abort_unless($request->user()->canPerform('post-transactions') || $savingsGoal->client_id === $request->user()->client?->id, 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['contribution', 'withdrawal', 'transfer_to_main_savings', 'goal_closure'])],
            'amount' => ['required_unless:type,goal_closure', 'numeric', 'gt:0'],
            'payment_method' => ['sometimes', 'in:cash,mobile_money,bank,internal_transfer'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $transaction = match ($data['type']) {
                'contribution' => $goals->contribute($savingsGoal, (float) $data['amount'], $request->user(), $data['payment_method'] ?? 'cash', $data['narration'] ?? null),
                'withdrawal' => $goals->withdraw($savingsGoal, (float) $data['amount'], $request->user(), $data['payment_method'] ?? 'cash', $data['narration'] ?? null),
                'transfer_to_main_savings' => $goals->transferToMainSavings($savingsGoal, (float) $data['amount'], $request->user(), $data['narration'] ?? null),
                'goal_closure' => $goals->close($savingsGoal, $request->user(), $data['narration'] ?? null),
            };
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new SavingsGoalTransactionResource($transaction), 'Savings goal transaction posted.', 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function goalData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'exists:clients,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'target_amount' => ['required', 'numeric', 'gt:0'],
            'current_saved_amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date', 'before_or_equal:target_date'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'contribution_frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'annually'])],
            'expected_contribution_amount' => ['required', 'numeric', 'min:0'],
            'savings_account_id' => ['nullable', 'exists:savings_accounts,id'],
            'status' => ['sometimes', Rule::in(['active', 'achieved', 'cancelled', 'failed'])],
        ]);
    }
}
