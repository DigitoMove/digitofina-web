<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FixedSavingsAccountResource;
use App\Models\FixedSavingsAccount;
use App\Services\FixedSavingsService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FixedSavingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-reports') || $request->user()->canPerform('view-own-accounts'), 403);

        $accounts = ApiSecurity::scopeTenant(FixedSavingsAccount::query()->with('client'), $request->user())
            ->when($request->user()->isMember(), fn ($query) => $query->where('client_id', $request->user()->client?->id))
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return ApiResponse::collection(FixedSavingsAccountResource::collection($accounts), 'Fixed savings accounts retrieved.');
    }

    public function store(Request $request, FixedSavingsService $fixedSavings): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'savings_account_id' => ['nullable', 'exists:savings_accounts,id'],
            'principal_amount' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date'],
            'maturity_date' => ['required', 'date', 'after:start_date'],
            'interest_rate' => ['required', 'numeric', 'min:0'],
            'early_withdrawal_allowed' => ['sometimes', 'boolean'],
            'early_withdrawal_penalty' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $account = $fixedSavings->open($data, $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new FixedSavingsAccountResource($account->load('client')), 'Fixed savings account opened.', 201);
    }
}
