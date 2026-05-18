<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, User $officer, ?UploadedFile $profilePhoto = null): Client
    {
        $client = DB::transaction(function () use ($data, $officer): Client {
            $branch = Branch::findOrFail($data['branch_id']);
            $memberUser = $this->memberUser($data, $branch, $officer);

            $client = Client::create($data + [
                'organization_id' => $officer->organization_id,
                'officer_id' => $officer->id,
                'user_id' => $memberUser?->id,
                'client_number' => $this->numbers->clientNumber($branch),
                'registered_at' => now()->toDateString(),
            ]);

            $this->ensureBasicSavingsAccount($client->load('branch'), $officer);
            $this->auditLogger->record('client.created', $client, $client->toArray());

            return $client;
        });

        $this->storeProfilePhoto($client, $profilePhoto);

        return $client->refresh();
    }

    public function ensureBasicSavingsAccount(Client $client, User $user): Account
    {
        return DB::transaction(function () use ($client, $user): Account {
            $client = Client::query()->with(['branch', 'accounts.accountType'])->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $existing = $client->accounts->first(fn (Account $account): bool => $account->accountType?->category === 'savings');

            if ($existing) {
                return $existing;
            }

            if ($client->savingsAccounts()->exists()) {
                throw ValidationException::withMessages(['client_id' => 'This client already has a savings package account. Remove that package before generating a basic savings account.']);
            }

            $type = AccountType::query()->where('category', 'savings')->where('is_active', true)->firstOrFail();
            $accountName = trim(($client->full_name ?: $client->business_name ?: $client->client_number).' Savings Account');

            $account = Account::create([
                'organization_id' => $client->organization_id ?? $user->organization_id,
                'client_id' => $client->id,
                'branch_id' => $client->branch_id,
                'account_type_id' => $type->id,
                'account_number' => $this->numbers->accountNumber($client->branch, $type),
                'account_name' => $accountName,
                'status' => 'active',
                'balance' => 0,
                'opened_at' => now()->toDateString(),
            ]);

            $this->auditLogger->record('client.basic_savings_account_generated', $account, [
                'client_id' => $client->id,
                'account_number' => $account->account_number,
            ]);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Client $client, array $data, User $user, ?UploadedFile $profilePhoto = null): Client
    {
        $old = $client->toArray();
        $client->update($data);
        $this->storeProfilePhoto($client, $profilePhoto);
        $this->auditLogger->record('client.updated', $client, $client->fresh()->toArray(), $old);

        return $client->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function memberUser(array $data, Branch $branch, User $officer): ?User
    {
        if (blank($data['email'] ?? null)) {
            return null;
        }

        return User::firstOrCreate(['email' => $data['email']], [
            'organization_id' => $officer->organization_id,
            'branch_id' => $branch->id,
            'role_id' => Role::where('slug', 'member')->value('id'),
            'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
            'phone' => $data['phone'] ?? null,
            'position' => 'Member',
            'status' => 'active',
            'password' => Hash::make(Str::password(12)),
        ]);
    }

    private function storeProfilePhoto(Client $client, ?UploadedFile $profilePhoto): void
    {
        if (! $profilePhoto) {
            return;
        }

        $client->update([
            'photo_path' => $profilePhoto->store("clients/{$client->id}/profile", 'local'),
        ]);
    }
}
