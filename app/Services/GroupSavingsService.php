<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\GroupSavingsAccount;
use App\Models\GroupSavingsContribution;
use App\Models\User;
use App\Support\CurrencyDenomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GroupSavingsService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private JournalEntryService $journalEntryService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): GroupSavingsAccount
    {
        $group = isset($data['client_group_id']) ? ClientGroup::findOrFail($data['client_group_id']) : null;

        $account = GroupSavingsAccount::create([
            'organization_id' => $user->organization_id,
            'client_group_id' => $group?->id,
            'branch_id' => $data['branch_id'] ?? $group?->branch_id ?? $user->branch_id,
            'savings_account_id' => $data['savings_account_id'] ?? null,
            'created_by' => $user->id,
            'name' => $data['name'] ?? $group?->name,
            'contribution_amount' => $data['contribution_amount'] ?? 0,
            'contribution_frequency' => $data['contribution_frequency'] ?? 'monthly',
            'meeting_day' => $data['meeting_day'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->auditLogger->record('savings.group_account_created', $account, $account->toArray());

        return $account;
    }

    public function contribute(GroupSavingsAccount $account, int $clientId, float $amount, User $user, string $paymentMethod = 'cash', ?string $narration = null): GroupSavingsContribution
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Contribution amount must be greater than zero.');
        }

        CurrencyDenomination::assertValid($amount);

        return DB::transaction(function () use ($account, $clientId, $amount, $user, $paymentMethod, $narration): GroupSavingsContribution {
            $account = GroupSavingsAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $client = Client::findOrFail($clientId);

            if ($account->status !== 'active') {
                throw new InvalidArgumentException('Only active group savings accounts can receive contributions.');
            }

            if ($account->client_group_id && ! $client->groups()->whereKey($account->client_group_id)->wherePivot('status', 'active')->exists()) {
                throw new InvalidArgumentException('Client is not an active member of this group.');
            }

            $before = (float) $account->group_savings_balance;
            $after = $before + $amount;
            $account->update(['group_savings_balance' => $after]);

            $contribution = GroupSavingsContribution::create([
                'organization_id' => $account->organization_id,
                'group_savings_account_id' => $account->id,
                'client_id' => $client->id,
                'created_by' => $user->id,
                'reference' => 'GS'.now()->format('ymdHis').Str::upper(Str::random(5)),
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'contribution_date' => today()->toDateString(),
                'payment_method' => $paymentMethod,
                'narration' => $narration,
                'status' => 'successful',
            ]);

            $this->journalEntryService->post([
                ['account_code' => '1000', 'debit' => $amount, 'narration' => 'Group savings cash received'],
                ['account_code' => '2100', 'credit' => $amount, 'narration' => 'Group savings liability increased'],
            ], "Group savings contribution {$contribution->reference}", $user, $account->branch_id);
            $this->auditLogger->record('savings.group_contribution_posted', $contribution, ['amount' => $amount]);

            return $contribution;
        });
    }
}
