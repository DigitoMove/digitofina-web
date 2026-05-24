<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
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

    private function accountNumber(int|string $branchId, SavingsProduct $product): string
    {
        $branch = Branch::findOrFail($branchId);
        $sequence = SavingsAccount::query()->where('branch_id', $branch->id)->where('savings_product_id', $product->id)->count() + 1;

        return $branch->code.'-'.$product->account_number_prefix.'-'.Str::padLeft((string) $sequence, 7, '0');
    }
}
