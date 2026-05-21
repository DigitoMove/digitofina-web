<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanRepaymentRequest;
use App\Models\DrawerSession;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\TransactionType;
use App\Services\LoanRepaymentService;
use App\Services\TransactionPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use InvalidArgumentException;

class LoanRepaymentController extends Controller
{
    public function store(StoreLoanRepaymentRequest $request, Loan $loan, LoanRepaymentService $repayments, TransactionPostingService $posting): RedirectResponse
    {
        $data = $request->validated();

        abort_unless($request->user()->isTellerCashier(), 403);

        if ($data['payment_method'] === 'cash') {
            try {
                $session = DrawerSession::query()
                    ->where('user_id', $request->user()->id)
                    ->where('status', 'open')
                    ->latest()
                    ->first();
                $loan->loadMissing('loanAccount');
                $type = TransactionType::query()->where('code', 'loan_repayment')->first();

                if (! $session) {
                    throw new InvalidArgumentException('Open your teller drawer before posting a cash loan repayment.');
                }

                if (! $loan->loanAccount) {
                    throw new InvalidArgumentException('This loan does not have a linked loan account.');
                }

                if (! $type) {
                    throw new InvalidArgumentException('Loan repayment transaction type is not configured.');
                }

                $transaction = $posting->postCash(
                    $loan->loanAccount,
                    $session,
                    $type,
                    (float) $data['amount'],
                    $request->user(),
                    'Loan repayment for '.$loan->loan_number,
                    null,
                    Carbon::parse($data['paid_at'])
                );
            } catch (InvalidArgumentException $exception) {
                return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
            }

            return redirect()->route('receipts.show', $transaction)->with('status', 'Loan repayment posted through teller drawer.');
        }

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
