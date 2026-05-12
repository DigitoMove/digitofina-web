<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoanApplicationService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, User $officer): Loan
    {
        $client = Client::with('branch')->findOrFail($data['client_id']);
        $product = LoanProduct::findOrFail($data['loan_product_id']);
        $savings = Account::where('client_id', $client->id)->whereHas('accountType', fn ($query) => $query->where('category', 'savings'))->first();
        $requestedAmount = (float) $data['requested_amount'];

        if ($requestedAmount < (float) $product->min_amount || $requestedAmount > (float) $product->max_amount) {
            throw ValidationException::withMessages(['requested_amount' => 'Requested amount is outside the selected product limits.']);
        }

        if ((int) $data['term_periods'] < (int) $product->min_term || (int) $data['term_periods'] > (int) $product->max_term) {
            throw ValidationException::withMessages(['term_periods' => 'Loan term is outside the selected product limits.']);
        }

        $status = match (true) {
            $product->require_guarantors => 'pending_guarantor_approval',
            $product->require_collateral => 'pending_collateral_verification',
            default => 'submitted',
        };

        $loan = Loan::create($data + [
            'organization_id' => $officer->organization_id,
            'branch_id' => $client->branch_id,
            'officer_id' => $officer->id,
            'savings_account_id' => $savings?->id,
            'loan_number' => $this->numbers->loanNumber($client->branch),
            'interest_rate' => $product->annual_interest_rate,
            'savings_balance_at_application' => (float) ($savings?->balance ?? 0),
            'required_savings_amount' => round($requestedAmount * ((float) $product->required_savings_percentage / 100), 2),
            'application_date' => now()->toDateString(),
            'status' => $status,
            'submitted_at' => now()->toDateString(),
        ]);

        $this->auditLogger->record('loan.submitted', $loan, $loan->toArray());

        return $loan->refresh();
    }
}
