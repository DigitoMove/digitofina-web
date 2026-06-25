<?php

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\Loan;
use App\Models\PaymentRequest;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OnlinePaymentPostingService
{
    /**
     * @var array<int, string>
     */
    private const SUCCESS_STATUSES = ['success', 'successful', 'completed', 'complete', 'paid'];

    public function __construct(
        private LoanRepaymentService $loanRepayments,
        private SavingsTransactionService $savingsTransactions,
        private InvestmentService $investments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function postIfSuccessful(PaymentRequest $paymentRequest, array $payload = []): ?Model
    {
        return DB::transaction(function () use ($paymentRequest, $payload): ?Model {
            $paymentRequest = PaymentRequest::query()
                ->with(['payable', 'postedTransaction'])
                ->whereKey($paymentRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($paymentRequest->posted_at) {
                return $paymentRequest->postedTransaction;
            }

            if (! $this->isSuccessful($payload['status'] ?? $paymentRequest->status)) {
                return null;
            }

            $paidAmount = (float) ($payload['amount'] ?? $paymentRequest->amount);
            $expectedAmount = (float) $paymentRequest->amount;

            if (round($paidAmount, 2) !== round($expectedAmount, 2)) {
                $paymentRequest->update([
                    'status' => 'suspicious',
                    'posting_error' => 'Confirmed payment amount does not match the initiated amount.',
                ]);

                return null;
            }

            $payable = $paymentRequest->payable;

            if (! $payable) {
                throw new InvalidArgumentException('Payment request is not linked to a payable record.');
            }

            $postedTransaction = $this->postToPayable($paymentRequest, $payable, $expectedAmount, $this->postingUser($paymentRequest, $payable));

            $paymentRequest->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_transaction_type' => $postedTransaction->getMorphClass(),
                'posted_transaction_id' => $postedTransaction->getKey(),
                'posting_error' => null,
            ]);

            return $postedTransaction;
        }, 3);
    }

    private function isSuccessful(mixed $status): bool
    {
        return in_array(strtolower((string) $status), self::SUCCESS_STATUSES, true);
    }

    private function postToPayable(PaymentRequest $paymentRequest, Model $payable, float $amount, User $user): Model
    {
        return match (true) {
            $payable instanceof Loan => $this->loanRepayments->repay(
                loan: $payable,
                amount: $amount,
                user: $user,
                paymentMethod: 'mobile_money',
                reference: $paymentRequest->reference,
                counterAccountCode: '1000',
                journalMemo: 'Online loan repayment '.$paymentRequest->reference,
            ),
            $payable instanceof SavingsAccount => $this->savingsTransactions->deposit(
                account: $payable,
                amount: $amount,
                teller: $user,
                paymentMethod: 'mobile_money',
                narration: 'Online savings deposit '.$paymentRequest->reference,
            ),
            $payable instanceof InvestmentAccount => $this->investments->topUp(
                investment: $payable,
                amount: $amount,
                user: $user,
                paymentMethod: 'mobile_money',
                narration: 'Online investment top-up '.$paymentRequest->reference,
                idempotencyKey: $paymentRequest->reference,
            ),
            default => throw new InvalidArgumentException('Unsupported online payment target.'),
        };
    }

    private function postingUser(PaymentRequest $paymentRequest, Model $payable): User
    {
        $metadata = $paymentRequest->metadata ?? [];
        $initiatedBy = isset($metadata['initiated_by']) ? (int) $metadata['initiated_by'] : null;

        if ($initiatedBy) {
            $user = User::query()->find($initiatedBy);

            if ($user) {
                return $user;
            }
        }

        $client = match (true) {
            $payable instanceof Loan => $payable->client,
            $payable instanceof SavingsAccount => $payable->client,
            $payable instanceof InvestmentAccount => $payable->client,
            default => null,
        };

        if ($client?->user) {
            return $client->user;
        }

        throw new InvalidArgumentException('Payment request has no posting user.');
    }
}
