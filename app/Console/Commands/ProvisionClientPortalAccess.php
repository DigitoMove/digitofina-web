<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientPortalAccessService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('clients:provision-portal-access {--dry-run : Report the affected clients without changing accounts}')]
#[Description('Provision or reset client portal accounts to the configured temporary password')]
class ProvisionClientPortalAccess extends Command
{
    public function handle(ClientPortalAccessService $portalAccess): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $provisioned = 0;
        $skipped = [];
        $failed = [];
        $duplicateLinkedUserIds = Client::query()
            ->withoutGlobalScope('tenant')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->pluck('user_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        Client::query()
            ->withoutGlobalScope('tenant')
            ->with(['user.role'])
            ->orderBy('id')
            ->chunkById(200, function ($clients) use ($portalAccess, $dryRun, $duplicateLinkedUserIds, &$provisioned, &$skipped, &$failed): void {
                foreach ($clients as $client) {
                    if ($client->user && ! $client->user->isMember()) {
                        $skipped[] = "{$client->client_number} (linked staff account)";

                        continue;
                    }

                    if ($client->user_id && in_array((int) $client->user_id, $duplicateLinkedUserIds, true)) {
                        $skipped[] = "{$client->client_number} (shared portal user)";

                        continue;
                    }

                    if ($dryRun) {
                        $provisioned++;

                        continue;
                    }

                    try {
                        $portalAccess->provisionDefaultAccess($client);
                        $provisioned++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed[] = "{$client->client_number} ({$exception->getMessage()})";
                    }
                }
            });

        $action = $dryRun ? 'would receive' : 'received';
        $this->info("{$provisioned} client(s) {$action} portal access with a forced temporary-password change.");

        $this->reportExceptions('Skipped', $skipped);
        $this->reportExceptions('Failed', $failed);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int, string> $clients */
    private function reportExceptions(string $label, array $clients): void
    {
        if ($clients === []) {
            return;
        }

        $this->warn("{$label} ".count($clients).' client(s): '.collect($clients)->take(20)->implode(', '));

        if (count($clients) > 20) {
            $this->warn('Only the first 20 are shown.');
        }
    }
}
