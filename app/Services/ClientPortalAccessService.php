<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientPortalAccessService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function issue(Client $client, string $temporaryPassword, User $actor): User
    {
        return DB::transaction(function () use ($client, $temporaryPassword, $actor): User {
            $client = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $normalizedPhone = PhoneNumber::normalize($client->phone);

            $phoneInUse = User::query()
                ->where('organization_id', $client->organization_id)
                ->whereKeyNot($client->user_id ?: 0)
                ->whereIn('phone', PhoneNumber::variants($normalizedPhone))
                ->exists();

            if ($phoneInUse) {
                throw ValidationException::withMessages(['password' => 'Another user in this organization already uses this phone number.']);
            }

            $roleId = Role::query()->where('slug', 'member')->value('id');
            abort_unless($roleId, 422, 'The Member role must exist before portal access can be issued.');

            $user = $client->user ?: new User;
            $user->fill([
                'organization_id' => $client->organization_id,
                'branch_id' => $client->branch_id,
                'role_id' => $roleId,
                'name' => $client->full_name ?: $client->business_name ?: $client->client_number,
                'email' => $client->email ?: strtolower($client->client_number).'@client.digitofina.invalid',
                'phone' => $normalizedPhone,
                'position' => 'Client',
                'status' => 'active',
                'is_active' => true,
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ])->save();

            $user->tokens()->delete();
            $client->update(['user_id' => $user->id]);
            $this->auditLogger->record('client.portal_access_issued', $client, ['portal_user_id' => $user->id], actor: $actor);

            return $user;
        });
    }
}
