<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveLoanRequest;
use App\Http\Requests\StoreLoanCollateralRequest;
use App\Http\Requests\StoreLoanGuarantorRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanScheduleResource;
use App\Models\LoanSchedule;
use App\Models\Loan;
use App\Services\LoanCalculatorService;
use App\Services\LoanApprovalService;
use App\Services\LoanDisbursementService;
use App\Services\LoanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanWorkflowController extends Controller
{
    public function submit(Request $request, Loan $loan): LoanResource
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        abort_unless(in_array($loan->status, ['draft', 'pending_guarantor_approval', 'pending_collateral_verification'], true), 422);

        $loan->update(['status' => 'submitted', 'submitted_at' => now()->toDateString()]);

        return new LoanResource($loan->fresh()->load(['client', 'product']));
    }

    public function addGuarantor(StoreLoanGuarantorRequest $request, Loan $loan): LoanResource
    {
        $loan->guarantors()->create($request->validated() + ['organization_id' => $loan->organization_id]);

        return new LoanResource($loan->fresh()->load(['client', 'product']));
    }

    public function addCollateral(StoreLoanCollateralRequest $request, Loan $loan): LoanResource
    {
        $data = $request->validated();
        $loan->collaterals()->create($data + [
            'organization_id' => $loan->organization_id,
            'client_id' => $loan->client_id,
            'valued_by' => $request->user()->id,
            'verified_by' => $data['status'] === 'verified' ? $request->user()->id : null,
        ]);

        return new LoanResource($loan->fresh()->load(['client', 'product']));
    }

    public function schedulePreview(Request $request, LoanCalculatorService $calculator): AnonymousResourceCollection
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

        return LoanScheduleResource::collection(collect($rows)->map(fn (array $row) => new LoanSchedule([
            'installment_number' => $row['installment'],
            'principal_due' => $row['principal'],
            'interest_due' => $row['interest'],
            'total_due' => $row['total'],
            'outstanding_amount' => $row['total'],
            'status' => 'preview',
        ])));
    }

    public function approve(ApproveLoanRequest $request, Loan $loan, LoanApprovalService $approvals): LoanResource
    {
        return new LoanResource($approvals->approve($loan, $request->user(), (float) $request->validated('approved_amount'), $request->validated('comments'))->load(['client', 'product', 'schedules']));
    }

    public function reject(Request $request, Loan $loan, LoanService $loans): LoanResource
    {
        abort_unless($request->user()->canPerform('approve-loans'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);

        return new LoanResource($loans->reject($loan, $request->user(), $data['reason'])->load(['client', 'product']));
    }

    public function disburse(Request $request, Loan $loan, LoanDisbursementService $disbursements): LoanResource
    {
        abort_unless($request->user()->canPerform('disburse-loans') || $request->user()->canPerform('manage-loans'), 403);

        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,mobile_money,bank_transfer,transfer_to_savings,cheque'],
        ]);

        return new LoanResource($disbursements->disburseToSavings($loan, $request->user(), $data['payment_method'])->load(['client', 'product', 'schedules']));
    }

    public function arrears(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canPerform('manage-loans') || $request->user()->canPerform('view-reports'), 403);

        return LoanScheduleResource::collection(
            LoanSchedule::query()
                ->with('loan')
                ->where('outstanding_amount', '>', 0)
                ->where(fn ($query) => $query->where('status', 'overdue')->orWhereDate('due_date', '<', now()->toDateString()))
                ->orderBy('due_date')
                ->paginate($request->integer('per_page', 30))
        );
    }

    public function close(Request $request, Loan $loan): LoanResource
    {
        abort_unless($request->user()->canPerform('manage-loans'), 403);
        abort_unless(((float) $loan->principal_balance + (float) $loan->interest_balance + (float) $loan->fees_balance + (float) $loan->penalty_balance) <= 0, 422);

        $loan->update(['status' => 'closed']);

        return new LoanResource($loan->fresh()->load(['client', 'product']));
    }
}
