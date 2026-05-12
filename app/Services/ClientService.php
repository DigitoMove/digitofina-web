<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        $branch = Branch::findOrFail($data['branch_id']);
        $memberUser = $this->memberUser($data, $branch, $officer);

        $client = Client::create($data + [
            'organization_id' => $officer->organization_id,
            'officer_id' => $officer->id,
            'user_id' => $memberUser?->id,
            'client_number' => $this->numbers->clientNumber($branch),
            'registered_at' => now()->toDateString(),
        ]);

        $this->storeProfilePhoto($client, $profilePhoto);
        $this->auditLogger->record('client.created', $client, $client->toArray());

        return $client->refresh();
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
