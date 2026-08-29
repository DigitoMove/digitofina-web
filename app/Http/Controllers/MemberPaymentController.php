<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberPaymentRequest;
use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\Loan;
use App\Models\PaymentRequest;
use App\Models\SavingsAccount;
use App\Services\NylonPayCollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class MemberPaymentController extends Controller
{
    public function repayLoan(StoreMemberPaymentRequest $request, Loan $loan, NylonPayCollectionService $payments): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless((int) $loan->client_id === (int) $client->id, 404);

        if (! in_array($loan->status, Loan::OPERATIONAL_STATUSES, true)) {
            throw ValidationException::withMessages(['amount' => 'Only active or in-arrears loans can receive online repayments.']);
        }

        $amount = (float) $request->validated('amount');
        $payoffAmount = $this->loanPayoffAmount($loan);

        if ($amount > $payoffAmount) {
            throw ValidationException::withMessages(['amount' => 'The repayment amount cannot exceed the loan balance including penalties.']);
        }

        $paymentRequest = $payments->initiate(
            payable: $loan,
            amount: $amount,
            phone: $request->validated('phone'),
            member: $request->user(),
            metadata: $this->metadata($request, $client, 'loan_repayment') + [
                'includes_penalties' => true,
                'principal_balance' => (float) $loan->principal_balance,
                'interest_balance' => (float) $loan->interest_balance,
                'fees_balance' => (float) $loan->fees_balance,
                'penalty_balance' => (float) $loan->penalty_balance,
                'payoff_amount' => $payoffAmount,
            ],
        );

        return $this->redirectWithPayment($paymentRequest);
    }

    public function depositSavings(StoreMemberPaymentRequest $request, SavingsAccount $savingsAccount, NylonPayCollectionService $payments): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless((int) $savingsAccount->client_id === (int) $client->id, 404);

        if ($savingsAccount->status !== 'active') {
            throw ValidationException::withMessages(['amount' => 'Only active savings accounts can receive online deposits.']);
        }

        $paymentRequest = $payments->initiate(
            payable: $savingsAccount,
            amount: (float) $request->validated('amount'),
            phone: $request->validated('phone'),
            member: $request->user(),
            metadata: $this->metadata($request, $client, 'savings_deposit'),
        );

        return $this->redirectWithPayment($paymentRequest);
    }

    public function topUpInvestment(StoreMemberPaymentRequest $request, InvestmentAccount $investmentAccount, NylonPayCollectionService $payments): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless((int) $investmentAccount->client_id === (int) $client->id, 404);
        $investmentAccount->loadMissing('plan');

        if ($investmentAccount->status !== 'active') {
            throw ValidationException::withMessages(['amount' => 'Only active investments can receive online top-ups.']);
        }

        if (! $investmentAccount->plan?->allow_top_ups) {
            throw ValidationException::withMessages(['amount' => 'This investment plan does not allow top-ups.']);
        }

        $paymentRequest = $payments->initiate(
            payable: $investmentAccount,
            amount: (float) $request->validated('amount'),
            phone: $request->validated('phone'),
            member: $request->user(),
            metadata: $this->metadata($request, $client, 'investment_top_up'),
        );

        return $this->redirectWithPayment($paymentRequest);
    }

    private function client(StoreMemberPaymentRequest $request): Client
    {
        return $request->user()->client()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(StoreMemberPaymentRequest $request, Client $client, string $purpose): array
    {
        return [
            'initiated_by' => $request->user()->id,
            'client_id' => $client->id,
            'channel' => 'member_portal',
            'purpose' => $purpose,
        ];
    }

    private function loanPayoffAmount(Loan $loan): float
    {
        return (float) $loan->principal_balance
            + (float) $loan->interest_balance
            + (float) $loan->fees_balance
            + (float) $loan->penalty_balance;
    }

    private function redirectWithPayment(PaymentRequest $paymentRequest): RedirectResponse
    {
        return redirect()->away($paymentRequest->checkout_url);
    }
}
