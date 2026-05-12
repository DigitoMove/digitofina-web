<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanRepaymentRequest;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Services\LoanRepaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class LoanRepaymentController extends Controller
{
    public function store(StoreLoanRepaymentRequest $request, Loan $loan, LoanRepaymentService $repayments): RedirectResponse
    {
        $data = $request->validated();
        $repayment = $repayments->repay(
            $loan,
            (float) $data['amount'],
            $request->user(),
            null,
            $data['payment_method'],
            $data['reference'] ?? null,
            Carbon::parse($data['paid_at'])
        );

        return redirect()->route('loan-repayments.receipt', $repayment)->with('status', 'Loan repayment posted.');
    }

    public function receipt(LoanRepayment $loanRepayment): View
    {
        return view('loan-repayments.receipt', [
            'repayment' => $loanRepayment->load(['loan.client', 'branch', 'receivedBy']),
        ]);
    }
}
