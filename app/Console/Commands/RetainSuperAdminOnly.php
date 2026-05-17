<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Client;
use App\Models\TellerDrawer;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('users:retain-super-admin-only')]
#[Description('Soft-delete every non-super-admin user while preserving roles and permissions')]
class RetainSuperAdminOnly extends Command
{
    public function handle(): int
    {
        $superAdmin = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', 'super-admin'))
            ->orderByRaw('case when email = ? then 0 else 1 end', ['admin@digitofina.test'])
            ->first();

        if (! $superAdmin) {
            $this->error('No active super admin user was found. Assign one user to the super-admin role first.');

            return self::FAILURE;
        }

        $deletedUsers = 0;

        DB::transaction(function () use ($superAdmin, &$deletedUsers): void {
            $superAdmin->forceFill([
                'status' => 'active',
                'is_active' => true,
            ])->save();

            Branch::query()->update(['manager_user_id' => $superAdmin->id]);
            TellerDrawer::query()
                ->whereNotNull('assigned_user_id')
                ->where('assigned_user_id', '!=', $superAdmin->id)
                ->update(['assigned_user_id' => null]);
            Client::query()
                ->whereNotNull('user_id')
                ->where('user_id', '!=', $superAdmin->id)
                ->update(['user_id' => null]);

            $deletedUsers = User::query()
                ->whereKeyNot($superAdmin->id)
                ->delete();
        });

        $this->info("Kept {$superAdmin->name} ({$superAdmin->email}) as the only active system user.");
        $this->info("Soft-deleted {$deletedUsers} other user(s). Roles and permissions were not changed.");

        return self::SUCCESS;
    }
}
