<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use App\Notifications\AccountActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class InAppNotificationService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyUser(?User $user, string $title, string $message, ?string $actionUrl = null, string $category = 'account', string $level = 'info', array $meta = []): void
    {
        if (! $user || $user->status !== 'active' || $this->shouldSkipUser($user, $meta)) {
            return;
        }

        $user->notify(new AccountActivityNotification($title, $message, $actionUrl, $category, $level, $meta));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyClient(?Client $client, string $title, string $message, ?string $actionUrl = null, string $category = 'account', string $level = 'info', array $meta = []): void
    {
        $client?->loadMissing('user');

        $this->notifyUser($client?->user, $title, $message, $actionUrl, $category, $level, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyAccountClient(Account $account, string $title, string $message, ?string $actionUrl = null, string $category = 'account', string $level = 'info', array $meta = []): void
    {
        $account->loadMissing('client.user');

        $this->notifyClient($account->client, $title, $message, $actionUrl, $category, $level, $meta + [
            'account_id' => $account->id,
            'account_number' => $account->account_number,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyLoanOfficer(Loan $loan, string $title, string $message, ?string $actionUrl = null, string $category = 'loan', string $level = 'info', array $meta = []): void
    {
        $loan->loadMissing('officer');

        $this->notifyUser($loan->officer, $title, $message, $actionUrl, $category, $level, $this->loanMeta($loan) + $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyLoanClient(Loan $loan, string $title, string $message, ?string $actionUrl = null, string $category = 'loan', string $level = 'info', array $meta = []): void
    {
        $loan->loadMissing('client.user');

        $this->notifyClient($loan->client, $title, $message, $actionUrl, $category, $level, $this->loanMeta($loan) + $meta);
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $meta
     */
    public function notifyStaffByPermission(array $permissions, ?int $branchId, ?int $organizationId, string $title, string $message, ?string $actionUrl = null, string $category = 'system', string $level = 'info', array $meta = []): void
    {
        $this->staffQuery($branchId, $organizationId)
            ->where(function (Builder $query) use ($permissions): void {
                $query->whereHas('role', fn (Builder $roleQuery): Builder => $roleQuery->where('slug', 'super-admin'))
                    ->orWhereHas('role.permissions', fn (Builder $permissionQuery): Builder => $permissionQuery->whereIn('slug', $permissions));
            })
            ->get()
            ->filter(fn (User $user): bool => $user->hasRole('super-admin') || collect($permissions)->contains(fn (string $permission): bool => $user->canPerform($permission)))
            ->each(fn (User $user) => $this->notifyUser($user, $title, $message, $actionUrl, $category, $level, $meta));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyCashiers(?int $branchId, ?int $organizationId, string $title, string $message, ?string $actionUrl = null, string $category = 'drawer', string $level = 'info', array $meta = []): void
    {
        User::query()
            ->with('role')
            ->where('status', 'active')
            ->when($organizationId, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->when($branchId, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'teller-cashier'))
            ->get()
            ->each(fn (User $user) => $this->notifyUser($user, $title, $message, $actionUrl, $category, $level, $meta));
    }

    /**
     * @return Builder<User>
     */
    private function staffQuery(?int $branchId, ?int $organizationId): Builder
    {
        return User::query()
            ->with('role')
            ->where('status', 'active')
            ->when($organizationId, fn (Builder $query): Builder => $query->where(function (Builder $organizationQuery) use ($organizationId): void {
                $organizationQuery->where('organization_id', $organizationId)->orWhereNull('organization_id');
            }))
            ->when($branchId, fn (Builder $query): Builder => $query->where(function (Builder $branchQuery) use ($branchId): void {
                $branchQuery->where('branch_id', $branchId)->orWhereNull('branch_id');
            }));
    }

    /**
     * @return array<string, mixed>
     */
    private function loanMeta(Loan $loan): array
    {
        return [
            'loan_id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'client_id' => $loan->client_id,
            'branch_id' => $loan->branch_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function shouldSkipUser(User $user, array $meta): bool
    {
        $excludedUserIds = array_map('intval', Arr::wrap($meta['exclude_user_ids'] ?? []));

        if (isset($meta['actor_id'])) {
            $excludedUserIds[] = (int) $meta['actor_id'];
        }

        return in_array((int) $user->id, $excludedUserIds, true);
    }
}
