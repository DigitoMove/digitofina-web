<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ClientPortalAccessService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function provisionDefaultAccess(Client $client, ?User $actor = null): User
    {
        return $this->issue($client, (string) config('client_portal.default_temporary_password'), $actor);
    }

    public function issue(Client $client, string $temporaryPassword, ?User $actor = null): User
    {
        return DB::transaction(function () use ($client, $temporaryPassword, $actor): User {
            $client = Client::query()->with('user.role')->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $normalizedPhone = PhoneNumber::normalize($client->phone);
            $role = Role::query()->firstOrCreate(['slug' => 'member'], ['name' => 'Member']);
            abort_if($client->user && ! $client->user->isMember(), 422, 'This client is linked to a staff account and needs separate portal-account review.');
            abort_if($client->user && (int) $client->user->organization_id !== (int) $client->organization_id, 422, 'The linked portal user belongs to another organization.');

            $user = $client->user ?: User::withTrashed()
                ->with('role')
                ->where('email', $this->portalEmail($client))
                ->first() ?: new User;
            abort_if($user->exists && ! $user->isMember(), 422, 'The generated client portal identity is already in use.');

            if ($user->trashed()) {
                $user->restore();
            }

            $user->fill([
                'organization_id' => $client->organization_id,
                'branch_id' => $client->branch_id,
                'role_id' => $role->id,
                'name' => $client->full_name ?: $client->business_name ?: $client->client_number,
                'email' => $user->exists ? $user->email : $this->portalEmail($client),
                'phone' => $normalizedPhone,
                'position' => 'Client',
                'status' => 'active',
                'is_active' => true,
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ])->save();

            $user->tokens()->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $client->update(['user_id' => $user->id]);
            $this->auditLogger->record('client.portal_access_issued', $client, ['portal_user_id' => $user->id], actor: $actor);

            return $user;
        });
    }

    private function portalEmail(Client $client): string
    {
        return "client-{$client->organization_id}-{$client->id}@client.digitofina.invalid";
    }
}
