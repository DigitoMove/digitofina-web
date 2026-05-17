<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSavingsAccountProvisioningService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
    ) {}

    public function ensure(User $user, User $createdBy): Account
    {
        return DB::transaction(function () use ($user, $createdBy): Account {
            $branch = Branch::query()->findOrFail($user->branch_id);
            $client = Client::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'organization_id' => $user->organization_id,
                    'branch_id' => $branch->id,
                    'officer_id' => $createdBy->id,
                    'client_number' => $this->numbers->clientNumber($branch),
                    'client_type' => 'individual',
                    'first_name' => $this->firstName($user->name),
                    'last_name' => $this->lastName($user->name),
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'kyc_status' => 'pending',
                    'status' => 'active',
                    'registered_at' => now()->toDateString(),
                ],
            );

            $type = AccountType::query()->where('category', 'savings')->firstOrFail();
            $account = Account::query()
                ->where('client_id', $client->id)
                ->where('account_type_id', $type->id)
                ->first();

            if ($account) {
                return $account;
            }

            $account = Account::create([
                'organization_id' => $user->organization_id,
                'client_id' => $client->id,
                'branch_id' => $branch->id,
                'account_type_id' => $type->id,
                'account_number' => $this->numbers->accountNumber($branch, $type),
                'account_name' => $user->name.' Savings Account',
                'status' => 'active',
                'balance' => 0,
                'opened_at' => now()->toDateString(),
            ]);

            $this->auditLogger->record('user.savings_account_created', $account, [
                'user_id' => $user->id,
                'client_id' => $client->id,
                'account_number' => $account->account_number,
            ]);

            return $account;
        });
    }

    private function firstName(string $name): string
    {
        return str($name)->squish()->before(' ')->toString() ?: $name;
    }

    private function lastName(string $name): string
    {
        $parts = str($name)->squish()->explode(' ');

        if ($parts->count() <= 1) {
            return 'User';
        }

        return $parts->skip(1)->implode(' ');
    }
}
