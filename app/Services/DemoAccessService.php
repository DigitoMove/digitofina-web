<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DemoAccessService
{
    public function __construct(private TenantContext $tenant) {}

    public function isAvailableFor(Request $request): bool
    {
        if ($this->tenant->organization() !== null) {
            return false;
        }

        $host = Str::lower($request->getHost());

        return collect($this->hiddenHosts())
            ->doesntContain(fn (string $pattern): bool => Str::is(Str::lower($pattern), $host));
    }

    /**
     * @return array<int, string>
     */
    public function hiddenHosts(): array
    {
        $configuredHosts = config('demo.hidden_hosts', []);

        if (! Schema::hasTable('system_settings')) {
            return $configuredHosts;
        }

        $storedHosts = SystemSetting::forPlatform()
            ->where('key', 'demo_hidden_hosts')
            ->value('value');

        if (! filled($storedHosts)) {
            return $configuredHosts;
        }

        $decodedHosts = json_decode($storedHosts, true);

        return is_array($decodedHosts)
            ? array_values(array_filter(array_map('strval', $decodedHosts)))
            : $configuredHosts;
    }

    public function ensurePlatformSetting(): void
    {
        SystemSetting::withoutGlobalScope('tenant')->firstOrCreate(
            ['organization_id' => null, 'key' => 'demo_hidden_hosts'],
            [
                'label' => 'Domains without landing page or demo signup',
                'value' => json_encode(config('demo.hidden_hosts', []), JSON_PRETTY_PRINT),
                'type' => 'json',
                'group' => 'platform',
                'description' => 'JSON array of exact hosts or wildcard patterns. Registered organization domains and subdomains are always login-only.',
            ],
        );
    }

    public function assertValidHiddenHosts(string $value): void
    {
        try {
            $hosts = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Domains without landing page or demo signup must be a valid JSON array.');
        }

        if (! is_array($hosts) || ! array_is_list($hosts) || collect($hosts)->contains(fn (mixed $host): bool => ! is_string($host) || blank(trim($host)))) {
            throw new \InvalidArgumentException('Domains without landing page or demo signup must be a JSON array of host names or wildcard patterns.');
        }
    }
}
