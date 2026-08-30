<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Support\ApiSecurity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientService
{
    public function __construct(
        private AccountNumberGenerator $numbers,
        private AuditLogger $auditLogger,
        private InAppNotificationService $notifications,
        private ClientPortalAccessService $portalAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, User $officer, ?UploadedFile $profilePhoto = null): Client
    {
        $client = DB::transaction(function () use ($data, $officer): Client {
            $branch = Branch::findOrFail($data['branch_id']);

            $client = Client::create($data + [
                'organization_id' => $officer->organization_id,
                'officer_id' => $officer->id,
                'user_id' => null,
                'client_number' => $this->numbers->clientNumber($branch),
                'registered_at' => now()->toDateString(),
            ]);

            $this->portalAccess->provisionDefaultAccess($client, $officer);
            $this->ensureBasicSavingsAccount($client->load('branch'), $officer);
            $this->auditLogger->record('client.created', $client, $client->toArray());
            $this->notifications->notifyUser(
                $officer,
                'Client registered',
                "{$client->full_name} has been registered under your branch.",
                route('clients.show', $client),
                'client',
                'success',
                ['client_id' => $client->id, 'client_number' => $client->client_number, 'actor_id' => $officer->id],
            );
            $this->notifications->notifyClient(
                $client->loadMissing('user'),
                'Client account created',
                'Your client profile has been created.',
                route('clients.show', $client),
                'client',
                'success',
                ['client_id' => $client->id, 'client_number' => $client->client_number],
            );

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

            $type = AccountType::query()->firstOrCreate(
                ['code' => 'SAV'],
                [
                    'organization_id' => $client->organization_id ?? $user->organization_id,
                    'name' => 'Savings Account',
                    'category' => 'savings',
                    'minimum_balance' => 0,
                    'is_active' => true,
                ],
            );
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
            $this->notifications->notifyAccountClient(
                $account,
                'Savings account generated',
                "Savings account {$account->account_number} has been generated.",
                route('clients.accounts', $client),
                'account',
                'success',
            );

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
        $this->notifications->notifyClient(
            $client->loadMissing('user'),
            'Client profile updated',
            'Your client profile details have been updated.',
            route('clients.show', $client),
            'client',
            'info',
            ['client_id' => $client->id, 'client_number' => $client->client_number],
        );

        return $client->refresh();
    }

    public function assignOfficer(Client $client, User $user, int $officerId): Client
    {
        abort_unless($user->hasRole('super-admin') || $user->canPerform('manage-users') || $user->canPerform('disburse-loans') || $user->canPerform('manage-organization'), 403);

        return DB::transaction(function () use ($client, $user, $officerId): Client {
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $assignedOfficer = User::query()
                ->with('role')
                ->where('status', 'active')
                ->whereKey($officerId)
                ->firstOrFail();

            if (! in_array($assignedOfficer->role?->slug, ['branch-manager', 'loan-officer'], true)) {
                throw new InvalidArgumentException('Select an active branch head or Loan Officer.');
            }

            if ($client->organization_id && $assignedOfficer->organization_id && (int) $client->organization_id !== (int) $assignedOfficer->organization_id) {
                throw new InvalidArgumentException('The assigned officer must belong to this organization.');
            }

            if ($client->branch_id && $assignedOfficer->branch_id && (int) $client->branch_id !== (int) $assignedOfficer->branch_id && ! ApiSecurity::hasGlobalBranchAccess($user)) {
                throw new InvalidArgumentException('The assigned officer must belong to this branch.');
            }

            $oldOfficerId = $client->officer_id;
            $client->update(['officer_id' => $assignedOfficer->id]);

            $this->auditLogger->record('client.officer_assigned', $client, [
                'old_officer_id' => $oldOfficerId,
                'new_officer_id' => $assignedOfficer->id,
                'assigned_by' => $user->id,
            ]);
            $this->notifications->notifyUser(
                $assignedOfficer,
                'Client assigned to you',
                "{$client->full_name} has been assigned to you.",
                route('clients.show', $client),
                'client',
                'info',
                ['client_id' => $client->id, 'client_number' => $client->client_number, 'actor_id' => $user->id],
            );

            return $client->refresh();
        });
    }

    private function storeProfilePhoto(Client $client, ?UploadedFile $profilePhoto): void
    {
        if (! $profilePhoto) {
            return;
        }

        $client->update([
            'photo_path' => $profilePhoto->store("private/organizations/{$client->organization_id}/clients/{$client->id}/profile", 'local'),
        ]);
    }
}
