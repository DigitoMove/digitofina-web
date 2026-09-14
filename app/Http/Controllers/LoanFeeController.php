<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefundLoanApplicationFeeRequest;
use App\Http\Requests\UpdateLoanApplicationFeeRequest;
use App\Models\Loan;
use App\Services\LoanFeeAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class LoanFeeController extends Controller
{
    public function update(UpdateLoanApplicationFeeRequest $request, Loan $loan, LoanFeeAdjustmentService $fees): RedirectResponse
    {
        Gate::authorize('view', $loan);
        $data = $request->validated();

        try {
            $fees->setOverride($loan, $request->user(), isset($data['application_fee_override']) ? (float) $data['application_fee_override'] : null, $data['application_fee_override_reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['application_fee_override' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Application fee setting saved.');
    }

    public function refund(RefundLoanApplicationFeeRequest $request, Loan $loan, int $disbursement, LoanFeeAdjustmentService $fees): RedirectResponse
    {
        Gate::authorize('view', $loan);
        $data = $request->validated();

        try {
            $fees->refund($loan, $disbursement, $request->user(), (float) $data['amount'], $data['reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['application_fee_refund' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Application fee refund credited to client savings.');
    }
}
