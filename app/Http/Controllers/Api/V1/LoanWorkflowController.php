<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\StoreLoanCollateralRequest;
use App\Http\Requests\StoreLoanGuarantorRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleResource;
use App\Http\Resources\MakerCheckerRequestResource;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\AuditLogger;
use App\Services\FinancialControlService;
use App\Services\LoanApprovalService;
use App\Services\LoanCalculatorService;
use App\Services\LoanDisbursementService;
use App\Services\LoanService;
use App\Support\ApiQuery;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LoanWorkflowController extends Controller
{
    public function submit(Request $request, Loan $loan): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        abort_unless(in_array($loan->status, ['draft', 'pending_guarantor_approval', 'pending_collateral_verification'], true), 422);

        $loan->update(['status' => 'submitted', 'submitted_at' => now()->toDateString()]);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan application submitted successfully.');
    }

    public function addGuarantor(StoreLoanGuarantorRequest $request, Loan $loan): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $loan->loanGuarantors()->create($request->validated() + ['organization_id' => $loan->organization_id]);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan guarantor added successfully.', 201);
    }

    public function addCollateral(StoreLoanCollateralRequest $request, Loan $loan): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validated();
        $loan->collaterals()->create($data + [
            'organization_id' => $loan->organization_id,
            'client_id' => $loan->client_id,
            'valued_by' => $request->user()->id,
            'verified_by' => $data['status'] === 'verified' ? $request->user()->id : null,
        ]);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan collateral added successfully.', 201);
    }

    public function schedulePreview(Request $request, LoanCalculatorService $calculator): JsonResponse
    {
        $data = $request->validate([
            'principal' => ['required', 'numeric', 'min:1'],
            'annual_interest_rate' => ['required', 'numeric', 'min:0'],
            'installments' => ['required', 'integer', 'min:1'],
            'interest_method' => ['required', 'in:flat,declining_balance,reducing_balance_equal_installments'],
            'repayment_frequency' => ['required', 'in:daily,weekly,bi-weekly,monthly,quarterly'],
        ]);
        $periodicRate = match ($data['repayment_frequency']) {
            'daily' => (float) $data['annual_interest_rate'] / 365,
            'weekly' => (float) $data['annual_interest_rate'] / 52,
            'bi-weekly' => (float) $data['annual_interest_rate'] / 26,
            'quarterly' => (float) $data['annual_interest_rate'] / 4,
            default => (float) $data['annual_interest_rate'] / 12,
        };

        $rows = match ($data['interest_method']) {
            'flat' => $calculator->flatRate((float) $data['principal'], $periodicRate, (int) $data['installments']),
            'reducing_balance_equal_installments' => $calculator->reducingBalanceEqualInstallments((float) $data['principal'], $periodicRate, (int) $data['installments']),
            default => $calculator->decliningBalance((float) $data['principal'], $periodicRate, (int) $data['installments']),
        };

        return ApiResponse::collection(
            LoanScheduleResource::collection(collect($rows)->map(fn (array $row) => new LoanSchedule([
                'installment_number' => $row['installment'],
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => $row['total'],
                'outstanding_amount' => $row['total'],
                'status' => 'preview',
            ]))),
            'Repayment schedule preview generated.'
        );
    }

    public function approve(ApproveLoanRequest $request, Loan $loan, LoanApprovalService $approvals, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $amount = (float) $request->validated('approved_amount');

        if ($controls->requiresMakerChecker($request->user(), 'loan_approval', $amount)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'loan_approval',
                subject: $loan,
                payload: [
                    'approved_amount' => $amount,
                    'comments' => $request->validated('comments'),
                ],
                amount: $amount,
                requiredPermission: 'approve-loans',
                reason: $request->validated('comments'),
                oldValues: $loan->only(['status', 'approved_amount', 'principal_balance']),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Loan approval submitted for maker-checker review.', 202);
        }

        try {
            $approvedLoan = $approvals->approve($loan, $request->user(), $amount, $request->validated('comments'));
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(
            new LoanResource($approvedLoan->load(['client', 'product', 'schedules'])),
            'Loan approved successfully.'
        );
    }

    public function reject(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        abort_unless($request->user()->canPerform('approve-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);

        try {
            $rejectedLoan = $loans->reject($loan, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new LoanResource($rejectedLoan->load(['client', 'product'])), 'Loan rejected successfully.');
    }

    public function disburse(Request $request, Loan $loan, LoanDisbursementService $disbursements, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        abort_unless($request->user()->canPerform('disburse-loans') || $request->user()->canPerform('manage-loans'), 403);

        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        if ($controls->requiresMakerChecker($request->user(), 'loan_disbursement', (float) $loan->approved_amount)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'loan_disbursement',
                subject: $loan,
                payload: $data,
                amount: (float) $loan->approved_amount,
                requiredPermission: 'disburse-loans',
                reason: 'Loan disbursement requires checker approval.',
                oldValues: $loan->only(['status', 'disbursed_at', 'principal_balance']),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Loan disbursement submitted for maker-checker review.', 202);
        }

        try {
            $disbursedLoan = $disbursements->disburseToSavings($loan, $request->user(), $data['payment_method']);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(
            new LoanResource($disbursedLoan->load(['client', 'product', 'schedules'])),
            'Loan disbursed successfully.'
        );
    }

    public function arrears(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('view-reports'), 403);

        $arrearsQuery = LoanSchedule::query()
            ->with('loan')
            ->whereHas('loan', fn ($query) => ApiSecurity::scopeTenant($query, $request->user()))
            ->where('outstanding_amount', '>', 0)
            ->where(fn ($query) => $query->where('status', 'overdue')->orWhereDate('due_date', '<', now()->toDateString()));

        $arrears = ApiQuery::apply(
            $arrearsQuery,
            $request,
            [],
            ['status'],
            ['id', 'due_date', 'outstanding_amount', 'days_overdue', 'status', 'created_at', 'updated_at'],
            'due_date',
            'asc',
        )->paginate(ApiQuery::perPage($request, 30));

        return ApiResponse::collection(LoanScheduleResource::collection($arrears), 'Loan arrears retrieved.', meta: ApiQuery::meta($request));
    }

    public function close(Request $request, Loan $loan): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        abort_unless(((float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance) <= 0, 422);

        $loan->update(['status' => 'closed']);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan closed successfully.');
    }

    public function writeOff(Request $request, Loan $loan, AuditLogger $auditLogger, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        ApiSecurity::assertPermission($request->user(), 'write-off-loans');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        abort_unless(in_array($loan->status, ['active', 'in_arrears', 'restructured'], true), 422);

        if ($controls->requiresMakerChecker($request->user(), 'loan_write_off', (float) $loan->principal_balance)) {
            $approval = $controls->requestAction(
                maker: $request->user(),
                action: 'loan_write_off',
                subject: $loan,
                payload: $data,
                amount: (float) $loan->principal_balance,
                requiredPermission: 'write-off-loans',
                reason: $data['reason'],
                oldValues: $loan->only(['status', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance']),
            );

            return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Loan write-off submitted for maker-checker review.', 202);
        }

        $old = $loan->only(['status', 'principal_balance', 'interest_balance', 'fees_balance', 'penalty_balance']);
        $loan->update(['status' => 'written_off']);

        $auditLogger->record('loan.written_off_api', $loan, [
            'reason' => $data['reason'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $old);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan written off successfully.');
    }

    public function waivePenalty(Request $request, Loan $loan, FinancialControlService $controls): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        ApiSecurity::assertPermission($request->user(), 'waive-penalties');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        abort_unless((float) $loan->penalty_balance > 0, 422);

        $approval = $controls->requestAction(
            maker: $request->user(),
            action: 'penalty_waiver',
            subject: $loan,
            payload: $data,
            amount: (float) $data['amount'],
            requiredPermission: 'waive-penalties',
            reason: $data['reason'],
            oldValues: $loan->only(['penalty_balance']),
        );

        return ApiResponse::resource(new MakerCheckerRequestResource($approval->load(['requestedBy', 'branch'])), 'Penalty waiver submitted for maker-checker review.', 202);
    }
}
