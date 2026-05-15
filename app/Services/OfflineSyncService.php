<?php

namespace App\Services;

use App\Models\Client;
use App\Models\OfflineSyncBatch;
use App\Models\OfflineSyncRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OfflineSyncService
{
    public function __construct(
        private readonly ClientService $clients,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncClients(User $user, array $data): OfflineSyncBatch
    {
        $payloadHash = hash('sha256', json_encode($data));
        $existing = $this->existingBatch($user, $data['client_batch_id'] ?? null);

        if ($existing) {
            return $existing->load('records');
        }

        return DB::transaction(function () use ($user, $data, $payloadHash): OfflineSyncBatch {
            $batch = OfflineSyncBatch::create([
                'organization_id' => $user->organization_id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'client_batch_id' => $data['client_batch_id'] ?? null,
                'device_name' => $data['device_name'] ?? request()?->header('X-Device-Name'),
                'payload_hash' => $payloadHash,
                'total_records' => count($data['records'] ?? []),
                'synced_at' => now(),
            ]);

            foreach ($data['records'] as $record) {
                $this->processClientRecord($batch, $user, $record);
            }

            $this->refreshCounts($batch);
            $this->audit->record('offline_sync.clients', $batch, $batch->only(['total_records', 'accepted_records', 'conflict_records', 'failed_records']), [], $user);

            return $batch->fresh('records');
        });
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function processClientRecord(OfflineSyncBatch $batch, User $user, array $record): OfflineSyncRecord
    {
        $payload = $record['payload'] ?? [];
        $syncRecord = OfflineSyncRecord::create([
            'offline_sync_batch_id' => $batch->id,
            'organization_id' => $batch->organization_id,
            'branch_id' => $payload['branch_id'] ?? $batch->branch_id,
            'user_id' => $user->id,
            'resource_type' => 'client',
            'action' => $record['action'],
            'local_temp_id' => $record['local_temp_id'],
            'client_updated_at' => $record['client_updated_at'],
            'payload' => $payload,
            'status' => 'processing',
        ]);

        $errors = $this->validateClientPayload($payload, $record, $user);

        if ($errors !== []) {
            $syncRecord->update(['status' => 'failed', 'validation_errors' => $errors]);

            return $syncRecord;
        }

        $existingByLocalId = Client::query()
            ->where('organization_id', $user->organization_id)
            ->where('local_temp_id', $record['local_temp_id'])
            ->first();

        if ($record['action'] === 'create') {
            return $existingByLocalId
                ? $this->acceptExisting($syncRecord, $existingByLocalId)
                : $this->createClient($syncRecord, $payload, $record, $user);
        }

        $client = Client::query()
            ->where('organization_id', $user->organization_id)
            ->whereKey($record['server_id'] ?? null)
            ->first();

        if (! $client) {
            $syncRecord->update(['status' => 'failed', 'validation_errors' => ['server_id' => ['Client was not found on the server.']]]);

            return $syncRecord;
        }

        if ($this->hasConflict($client, $record)) {
            $syncRecord->update([
                'status' => 'conflict',
                'server_record_type' => $client->getMorphClass(),
                'server_record_id' => $client->id,
                'conflict_data' => [
                    'reason' => 'server_newer_than_mobile_snapshot',
                    'server_updated_at' => $client->updated_at?->toIso8601String(),
                    'mobile_server_updated_at' => $record['server_updated_at'] ?? null,
                    'server_sync_version' => $client->sync_version,
                ],
                'response_data' => ['server_client' => $this->clientResponse($client)],
            ]);

            return $syncRecord;
        }

        return $this->updateClient($syncRecord, $client, $payload, $record, $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $record
     */
    private function createClient(OfflineSyncRecord $syncRecord, array $payload, array $record, User $user): OfflineSyncRecord
    {
        $client = $this->clients->register($this->clientPayload($payload, $record), $user);

        $client->update([
            'local_temp_id' => $record['local_temp_id'],
            'sync_source' => 'offline_mobile',
            'client_modified_at' => Carbon::parse($record['client_updated_at']),
            'synced_at' => now(),
        ]);

        return $this->acceptExisting($syncRecord, $client->refresh());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $record
     */
    private function updateClient(OfflineSyncRecord $syncRecord, Client $client, array $payload, array $record, User $user): OfflineSyncRecord
    {
        $data = $this->clientPayload($payload, $record);
        unset($data['branch_id']);

        $data['sync_source'] = 'offline_mobile';
        $data['client_modified_at'] = Carbon::parse($record['client_updated_at']);
        $data['synced_at'] = now();
        $data['sync_version'] = ((int) $client->sync_version) + 1;

        $this->clients->update($client, $data, $user);

        return $this->acceptExisting($syncRecord, $client->refresh());
    }

    private function acceptExisting(OfflineSyncRecord $syncRecord, Client $client): OfflineSyncRecord
    {
        $syncRecord->update([
            'status' => 'accepted',
            'server_record_type' => $client->getMorphClass(),
            'server_record_id' => $client->id,
            'response_data' => [
                'local_temp_id' => $syncRecord->local_temp_id,
                'server_id' => $client->id,
                'client_number' => $client->client_number,
                'sync_version' => $client->sync_version,
                'server_updated_at' => $client->updated_at?->toIso8601String(),
            ],
        ]);

        return $syncRecord;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function clientPayload(array $payload, array $record): array
    {
        $checklist = $payload['kyc_documents'] ?? [];

        return [
            'branch_id' => $payload['branch_id'],
            'client_type' => $payload['client_type'],
            'first_name' => $payload['first_name'] ?? null,
            'middle_name' => $payload['middle_name'] ?? null,
            'last_name' => $payload['last_name'] ?? null,
            'gender' => $payload['gender'] ?? null,
            'date_of_birth' => $payload['date_of_birth'] ?? null,
            'nationality' => $payload['nationality'] ?? null,
            'phone' => $payload['phone'],
            'alternative_phone' => $payload['alternative_phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'occupation' => $payload['occupation'] ?? null,
            'employer_name' => $payload['employer_name'] ?? null,
            'marital_status' => $payload['marital_status'] ?? null,
            'next_of_kin_name' => $payload['next_of_kin_name'] ?? null,
            'next_of_kin_phone' => $payload['next_of_kin_phone'] ?? null,
            'address' => $payload['address'] ?? null,
            'village' => $payload['village'] ?? null,
            'parish' => $payload['parish'] ?? null,
            'sub_county' => $payload['sub_county'] ?? null,
            'district' => $payload['district'] ?? null,
            'region' => $payload['region'] ?? null,
            'gps_latitude' => $payload['gps_latitude'] ?? null,
            'gps_longitude' => $payload['gps_longitude'] ?? null,
            'employment_status' => $payload['employment_status'] ?? null,
            'business_name' => $payload['business_name'] ?? null,
            'business_details' => $payload['business_details'] ?? null,
            'kyc_status' => $payload['kyc_status'] ?? 'pending',
            'risk_rating' => $payload['risk_rating'] ?? null,
            'status' => $payload['status'] ?? 'pending',
            'local_temp_id' => $record['local_temp_id'],
            'sync_source' => 'offline_mobile',
            'client_modified_at' => Carbon::parse($record['client_updated_at']),
            'synced_at' => now(),
            'kyc_documents' => [
                'identity_confirmed' => (bool) ($checklist['identity_confirmed'] ?? false),
                'address_confirmed' => (bool) ($checklist['address_confirmed'] ?? false),
                'photo_captured' => (bool) ($checklist['photo_captured'] ?? false),
                'signature_captured' => (bool) ($checklist['signature_captured'] ?? false),
                'next_of_kin_confirmed' => (bool) ($checklist['next_of_kin_confirmed'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $record
     * @return array<string, array<int, string>>
     */
    private function validateClientPayload(array $payload, array $record, User $user): array
    {
        $rules = [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'client_type' => ['required', Rule::in(['individual', 'group', 'business', 'institution'])],
            'first_name' => ['required_unless:client_type,group,business,institution', 'nullable', 'string', 'max:191'],
            'last_name' => ['required_unless:client_type,group,business,institution', 'nullable', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:40'],
            'national_id' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:191'],
        ];

        $validator = Validator::make($payload, $rules);
        $errors = $validator->errors()->toArray();

        if ($user->branch_id && (int) ($payload['branch_id'] ?? 0) !== (int) $user->branch_id) {
            $errors['branch_id'][] = 'The client branch is outside this officer access.';
        }

        if ($record['action'] === 'create' && filled($payload['national_id'] ?? null)) {
            $exists = Client::where('organization_id', $user->organization_id)
                ->where('national_id', $payload['national_id'])
                ->where('local_temp_id', '!=', $record['local_temp_id'])
                ->exists();

            if ($exists) {
                $errors['national_id'][] = 'National ID is already registered on the server.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function hasConflict(Client $client, array $record): bool
    {
        if (blank($record['server_updated_at'] ?? null)) {
            return false;
        }

        return $client->updated_at && $client->updated_at->gt(Carbon::parse($record['server_updated_at'])->addSecond());
    }

    /**
     * @return array<string, mixed>
     */
    private function clientResponse(Client $client): array
    {
        return [
            'id' => $client->id,
            'client_number' => $client->client_number,
            'full_name' => $client->full_name,
            'phone' => $client->phone,
            'status' => $client->status,
            'sync_version' => $client->sync_version,
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }

    private function refreshCounts(OfflineSyncBatch $batch): void
    {
        $counts = $batch->records()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $failed = (int) ($counts['failed'] ?? 0);
        $conflicts = (int) ($counts['conflict'] ?? 0);

        $batch->update([
            'accepted_records' => (int) ($counts['accepted'] ?? 0),
            'conflict_records' => $conflicts,
            'failed_records' => $failed,
            'status' => $failed > 0 ? 'failed' : ($conflicts > 0 ? 'conflict' : 'processed'),
        ]);
    }

    private function existingBatch(User $user, ?string $clientBatchId): ?OfflineSyncBatch
    {
        if (blank($clientBatchId)) {
            return null;
        }

        return OfflineSyncBatch::query()
            ->where('organization_id', $user->organization_id)
            ->where('client_batch_id', $clientBatchId)
            ->with('records')
            ->first();
    }
}
