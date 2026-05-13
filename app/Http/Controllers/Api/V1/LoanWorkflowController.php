<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\StoreLoanCollateralRequest;
use App\Http\Requests\StoreLoanGuarantorRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleResource;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\LoanApprovalService;
use App\Services\LoanCalculatorService;
use App\Services\LoanDisbursementService;
use App\Services\LoanService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanWorkflowController extends Controller
{
    public function submit(Request $request, Loan $loan): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        abort_unless(in_array($loan->status, ['draft', 'pending_guarantor_approval', 'pending_collateral_verification'], true), 422);

        $loan->update(['status' => 'submitted', 'submitted_at' => now()->toDateString()]);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan application submitted successfully.');
    }

    public function addGuarantor(StoreLoanGuarantorRequest $request, Loan $loan): JsonResponse
    {
        $loan->guarantors()->create($request->validated() + ['organization_id' => $loan->organization_id]);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan guarantor added successfully.', 201);
    }

    public function addCollateral(StoreLoanCollateralRequest $request, Loan $loan): JsonResponse
    {
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

    public function approve(ApproveLoanRequest $request, Loan $loan, LoanApprovalService $approvals): JsonResponse
    {
        return ApiResponse::resource(
            new LoanResource($approvals->approve($loan, $request->user(), (float) $request->validated('approved_amount'), $request->validated('comments'))->load(['client', 'product', 'schedules'])),
            'Loan approved successfully.'
        );
    }

    public function reject(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        abort_unless($request->user()->canPerform('approve-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);

        return ApiResponse::resource(new LoanResource($loans->reject($loan, $request->user(), $data['reason'])->load(['client', 'product'])), 'Loan rejected successfully.');
    }

    public function disburse(Request $request, Loan $loan, LoanDisbursementService $disbursements): JsonResponse
    {
        abort_unless($request->user()->canPerform('disburse-loans') || $request->user()->canPerform('manage-loans'), 403);

        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        return ApiResponse::resource(
            new LoanResource($disbursements->disburseToSavings($loan, $request->user(), $data['payment_method'])->load(['client', 'product', 'schedules'])),
            'Loan disbursed successfully.'
        );
    }

    public function arrears(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('view-reports'), 403);

        return ApiResponse::collection(
            LoanScheduleResource::collection(
                LoanSchedule::query()
                    ->with('loan')
                    ->where('outstanding_amount', '>', 0)
                    ->where(fn ($query) => $query->where('status', 'overdue')->orWhereDate('due_date', '<', now()->toDateString()))
                    ->orderBy('due_date')
                    ->paginate($request->integer('per_page', 30))
            ),
            'Loan arrears retrieved.'
        );
    }

    public function close(Request $request, Loan $loan): JsonResponse
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        abort_unless(((float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance) <= 0, 422);

        $loan->update(['status' => 'closed']);

        return ApiResponse::resource(new LoanResource($loan->fresh()->load(['client', 'product'])), 'Loan closed successfully.');
    }
}
