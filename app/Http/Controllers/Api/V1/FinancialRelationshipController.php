<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestmentTransactionResource;
use App\Http\Resources\LoanRepaymentResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\Client;
use App\Models\InvestmentAccount;
use App\Models\InvestmentTransaction;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\FinancialRelationshipService;
use App\Support\ApiResponse;
use App\Support\ApiSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FinancialRelationshipController extends Controller
{
    public function transferSavingsToInvestment(Request $request, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-transactions');
        $data = $request->validate([
            'savings_account_id' => ['required', 'exists:savings_accounts,id'],
            'investment_account_id' => ['required', 'exists:investment_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);

        $savingsAccount = SavingsAccount::findOrFail($data['savings_account_id']);
        $investmentAccount = InvestmentAccount::findOrFail($data['investment_account_id']);
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $result = $relationships->transferSavingsToInvestment($savingsAccount, $investmentAccount, (float) $data['amount'], $request->user(), $data['narration'] ?? null);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::data([
            'savings_transaction' => (new SavingsTransactionResource($result['savings_transaction']))->resolve($request),
            'investment_transaction' => (new InvestmentTransactionResource($result['investment_transaction']))->resolve($request),
            'receipt_reference' => $result['receipt_reference'],
        ], 'Savings transferred into investment successfully.', 201);
    }

    public function postInvestmentReturn(Request $request, InvestmentAccount $investmentAccount, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-transactions');
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'destination' => ['required', Rule::in(['investment', 'savings', 'reinvest', 'loan_repayment'])],
            'savings_account_id' => ['nullable', 'required_if:destination,savings', 'exists:savings_accounts,id'],
            'loan_id' => ['nullable', 'required_if:destination,loan_repayment', 'exists:loans,id'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);

        $savingsAccount = isset($data['savings_account_id']) ? SavingsAccount::findOrFail($data['savings_account_id']) : null;
        $loan = isset($data['loan_id']) ? Loan::findOrFail($data['loan_id']) : null;

        if ($savingsAccount) {
            ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);
        }

        if ($loan) {
            ApiSecurity::assertTenantAccess($request->user(), $loan);
        }

        try {
            $result = $relationships->postInvestmentReturn($investmentAccount, (float) $data['amount'], $request->user(), $data['destination'], $savingsAccount, $loan, $data['narration'] ?? null);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::data($this->serializeRelationshipResult($result, $request), 'Investment return posted successfully.', 201);
    }

    public function lockSavingsForLoan(Request $request, Loan $loan, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'manage-loans');
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validate([
            'savings_account_id' => ['required', 'exists:savings_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $savingsAccount = SavingsAccount::findOrFail($data['savings_account_id']);
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);

        try {
            $security = $relationships->lockSavingsForLoan($loan, $savingsAccount, (float) $data['amount'], $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::data($security->fresh()->toArray(), 'Savings locked as loan security.', 201);
    }

    public function lockInvestmentForLoan(Request $request, Loan $loan, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'manage-loans');
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validate([
            'investment_account_id' => ['required', 'exists:investment_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $investmentAccount = InvestmentAccount::findOrFail($data['investment_account_id']);
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $security = $relationships->lockInvestmentForLoan($loan, $investmentAccount, (float) $data['amount'], $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::data($security->fresh()->toArray(), 'Investment locked as loan security.', 201);
    }

    public function repayLoanFromSavings(Request $request, Loan $loan, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validate([
            'savings_account_id' => ['required', 'exists:savings_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $savingsAccount = SavingsAccount::findOrFail($data['savings_account_id']);
        ApiSecurity::assertTenantAccess($request->user(), $savingsAccount);

        try {
            $repayment = $relationships->repayLoanFromSavings($loan, $savingsAccount, (float) $data['amount'], $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new LoanRepaymentResource($repayment->load('receivedBy')), 'Loan repayment deducted from savings.', 201);
    }

    public function repayLoanFromInvestmentReturns(Request $request, Loan $loan, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertPermission($request->user(), 'post-repayments');
        ApiSecurity::assertTenantAccess($request->user(), $loan);
        $data = $request->validate([
            'investment_account_id' => ['required', 'exists:investment_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $investmentAccount = InvestmentAccount::findOrFail($data['investment_account_id']);
        ApiSecurity::assertTenantAccess($request->user(), $investmentAccount);

        try {
            $repayment = $relationships->repayLoanFromInvestmentReturns($loan, $investmentAccount, (float) $data['amount'], $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return ApiResponse::resource(new LoanRepaymentResource($repayment->load('receivedBy')), 'Loan repayment deducted from investment returns.', 201);
    }

    public function clientOverview(Client $client, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertTenantAccess(request()->user(), $client);

        return ApiResponse::data($relationships->clientOverview($client), 'Client financial overview loaded.');
    }

    public function combinedStatement(Request $request, Client $client, FinancialRelationshipService $relationships): JsonResponse
    {
        ApiSecurity::assertTenantAccess($request->user(), $client);
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return ApiResponse::data($relationships->combinedStatement($client, $data['from'] ?? null, $data['to'] ?? null), 'Combined member statement loaded.');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function serializeRelationshipResult(array $result, Request $request): array
    {
        return collect($result)->map(function (mixed $value) use ($request): mixed {
            if ($value instanceof SavingsTransactionResource || $value instanceof InvestmentTransactionResource || $value instanceof LoanRepaymentResource) {
                return $value->resolve($request);
            }

            if ($value instanceof SavingsTransaction) {
                return (new SavingsTransactionResource($value))->resolve($request);
            }

            if ($value instanceof InvestmentTransaction) {
                return (new InvestmentTransactionResource($value))->resolve($request);
            }

            if ($value instanceof LoanRepayment) {
                return (new LoanRepaymentResource($value))->resolve($request);
            }

            return $value;
        })->all();
    }
}
