<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsAccountRequest;
use App\Http\Resources\ReportExportResource;
use App\Http\Resources\SavingsAccountResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Jobs\GenerateStatement;
use App\Models\ReportExport;
use App\Models\SavingsAccount;
use App\Services\SavingsAccountService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SavingsAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts') || $request->user()->canPerform('view-own-accounts'), 403);

        $accountsQuery = ApiSecurity::scopeTenant(SavingsAccount::query(), $request->user())
            ->with(['client', 'branch', 'product'])
            ->when($request->user()->isMember(), fn ($query) => $query->where('client_id', $request->user()->client?->id))
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('account_number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($query) => $query->whereAny(['client_number', 'first_name', 'last_name', 'phone'], 'like', "%{$search}%"));
                });
            })
            ->when($request->integer('branch_id'), function ($query, int $branchId) use ($request): void {
                ApiSecurity::assertClientBranchAccess($request->user(), $branchId);
            });

        $accounts = ApiQuery::apply(
            $accountsQuery,
            $request,
            [],
            ['branch_id', 'status', 'savings_product_id', 'client_id'],
            ['id', 'account_number', 'current_balance', 'status', 'opening_date', 'created_at', 'updated_at'],
        )->paginate(ApiQuery::perPage($request, 15));

        return ApiResponse::collection(SavingsAccountResource::collection($accounts), 'Savings accounts retrieved.', meta: ApiQuery::meta($request));
    }

    public function store(StoreSavingsAccountRequest $request, SavingsAccountService $accounts): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-accounts'), 403);
        ApiSecurity::assertClientBranchAccess($request->user(), (int) $request->validated('branch_id'));

        return ApiResponse::resource(
            new SavingsAccountResource($accounts->open($request->validated(), $request->user())->load(['client', 'branch', 'product'])),
            'Savings account opened successfully.',
            201
        );
    }

    public function show(SavingsAccount $savingsAccount): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $savingsAccount);
        abort_unless(request()->user()->canPerform('manage-accounts') || $savingsAccount->client_id === request()->user()->client?->id, 403);

        return ApiResponse::resource(new SavingsAccountResource($savingsAccount->load(['client', 'branch', 'product'])), 'Savings account retrieved.');
    }

    public function statement(Request $request, SavingsAccount $savingsAccount): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);
        abort_unless($request->user()->canPerform('manage-accounts') || $savingsAccount->client_id === $request->user()->client?->id, 403);

        $transactionsQuery = $savingsAccount->transactions()
            ->getQuery()
            ->with(['account', 'teller'])
            ->when($request->date_from, fn ($query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($request->date_to, fn ($query, string $date) => $query->whereDate('transaction_date', '<=', $date));

        $transactions = ApiQuery::apply(
            $transactionsQuery,
            $request,
            ['reference', 'narration'],
            ['type', 'status', 'payment_method'],
            ['id', 'reference', 'type', 'amount', 'transaction_date', 'created_at', 'updated_at'],
            'transaction_date',
        )->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiQuery::perPage($request, 30));

        return ApiResponse::collection(SavingsTransactionResource::collection($transactions), 'Savings statement retrieved.', meta: ApiQuery::meta($request));
    }

    public function generateStatement(Request $request, SavingsAccount $savingsAccount): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);
        abort_unless($request->user()->canPerform('manage-accounts') || $savingsAccount->client_id === $request->user()->client?->id, 403);

        $data = $request->validate([
            'format' => ['nullable', 'in:pdf,xlsx,csv'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $export = ReportExport::create([
            'organization_id' => $savingsAccount->organization_id,
            'branch_id' => $savingsAccount->branch_id,
            'requested_by' => $request->user()->id,
            'report_type' => 'savings_statement',
            'format' => $data['format'] ?? 'pdf',
            'filters' => [
                'savings_account_id' => $savingsAccount->id,
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
            ],
            'status' => 'queued',
        ]);

        GenerateStatement::dispatch($export, $savingsAccount->id)->afterCommit();

        return ApiResponse::resource(new ReportExportResource($export), 'Statement generation queued.', 202);
    }

    public function update(Request $request, SavingsAccount $savingsAccount): Response
    {
        abort(405);
    }

    public function destroy(SavingsAccount $savingsAccount): Response
    {
        abort(405);
    }
}
