<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Support\ApiSecurity;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SavingsAccountService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private SavingsTransactionService $transactions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, User $user): SavingsAccount
    {
        $client = Client::findOrFail($data['client_id']);
        $product = SavingsProduct::findOrFail($data['savings_product_id']);
        $openingDeposit = (float) ($data['opening_deposit'] ?? 0);

        if ($openingDeposit > 0) {
            CurrencyDenomination::assertValid($openingDeposit, 'Opening deposit');
        }

        if ($client->status !== 'active') {
            throw ValidationException::withMessages(['client_id' => 'A client must be active before opening a savings account.']);
        }

        if (SavingsAccount::query()
            ->where('client_id', $client->id)
            ->where('savings_product_id', $product->id)
            ->exists()
        ) {
            throw ValidationException::withMessages(['savings_product_id' => 'This savings product package is already attached to the client. Choose a different package.']);
        }

        if ($openingDeposit > 0 && $openingDeposit < (float) $product->minimum_opening_balance) {
            throw ValidationException::withMessages(['opening_deposit' => 'Opening deposit is below the product minimum opening balance.']);
        }

        return DB::transaction(function () use ($data, $product, $openingDeposit, $user): SavingsAccount {
            $account = SavingsAccount::create([
                'organization_id' => $user->organization_id,
                'branch_id' => $data['branch_id'],
                'is_organization_wide' => ($data['is_organization_wide'] ?? false) && $user->hasAnyRole(['super-admin', 'organization-admin']),
                'client_id' => $data['client_id'],
                'savings_product_id' => $product->id,
                'savings_type' => $data['savings_type'] ?? $product->savings_type ?? 'ordinary',
                'created_by' => $user->id,
                'account_number' => $this->accountNumber($data['branch_id'], $product),
                'opening_date' => $data['opening_date'],
                'last_activity_at' => $openingDeposit > 0 ? now() : null,
                'current_balance' => 0,
                'available_balance' => 0,
                'status' => $data['status'],
            ]);

            if ($openingDeposit > 0) {
                if ($account->status !== 'active') {
                    $account->update(['status' => 'active']);
                }

                $this->transactions->deposit($account, $openingDeposit, $user, 'cash', 'Opening deposit');
                $account->refresh();
            }

            $this->auditLogger->record('savings_account.created', $account, $account->toArray());

            return $account->refresh();
        });
    }

    public function markDormant(SavingsAccount $account, User $user): SavingsAccount
    {
        $this->authorizeAccountChange($account, $user);

        return DB::transaction(function () use ($account, $user): SavingsAccount {
            $account = SavingsAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAccountChange($account, $user);

            if ($account->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Only active savings accounts can be marked dormant.']);
            }

            $account->update(['status' => 'dormant', 'dormant_at' => now()]);
            $this->auditLogger->record('savings_account.marked_dormant', $account, ['status' => 'dormant'], ['status' => 'active'], $user);

            return $account->refresh();
        });
    }

    public function reactivate(SavingsAccount $account, User $user): SavingsAccount
    {
        $this->authorizeAccountChange($account, $user);

        return DB::transaction(function () use ($account, $user): SavingsAccount {
            $account = SavingsAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAccountChange($account, $user);

            if ($account->status !== 'dormant') {
                throw ValidationException::withMessages(['status' => 'Only dormant savings accounts can be reactivated.']);
            }

            $account->update(['status' => 'active', 'dormant_at' => null]);
            $this->auditLogger->record('savings_account.reactivated', $account, [
                'status' => 'active',
                'account_number' => $account->account_number,
                'client_id' => $account->client_id,
            ], ['status' => 'dormant'], $user);

            return $account->refresh();
        });
    }

    public function adjustMinimumBalance(SavingsAccount $account, ?float $minimumBalance, string $reason, User $user): SavingsAccount
    {
        $this->authorizeAccountChange($account, $user);

        if ($minimumBalance !== null && $minimumBalance < 0) {
            throw ValidationException::withMessages(['minimum_balance' => 'Minimum balance cannot be negative.']);
        }

        if ($minimumBalance !== null && $minimumBalance > 0) {
            CurrencyDenomination::assertValid($minimumBalance, 'Minimum balance');
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the minimum balance adjustment.']);
        }

        return DB::transaction(function () use ($account, $minimumBalance, $reason, $user): SavingsAccount {
            $account = SavingsAccount::query()->with('product')->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAccountChange($account, $user);
            $oldMinimumBalance = $account->minimumBalance();
            $oldOverride = $account->minimum_balance_override;
            $account->update(['minimum_balance_override' => $minimumBalance]);
            $this->auditLogger->record('savings_account.minimum_balance_adjusted', $account, [
                'minimum_balance_override' => $minimumBalance,
                'effective_minimum_balance' => $account->minimumBalance(),
                'reason' => $reason,
            ], [
                'minimum_balance_override' => $oldOverride,
                'effective_minimum_balance' => $oldMinimumBalance,
            ], $user);

            return $account->refresh();
        });
    }

    private function authorizeAccountChange(SavingsAccount $account, User $user): void
    {
        abort_unless($user->canPerform('manage-accounts'), 403);
        ApiSecurity::assertTenantAccess($user, $account);
    }

    private function accountNumber(int|string $branchId, SavingsProduct $product): string
    {
        $branch = Branch::findOrFail($branchId);
        $sequence = SavingsAccount::query()->where('branch_id', $branch->id)->where('savings_product_id', $product->id)->count() + 1;

        return $branch->code.'-'.$product->account_number_prefix.'-'.Str::padLeft((string) $sequence, 7, '0');
    }
}
