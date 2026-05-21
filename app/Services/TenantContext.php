<?php

namespace App\Services;

use App\Models\Organization;

class TenantContext
{
    private ?Organization $organization = null;

    private bool $scoped = false;

    public function set(?Organization $organization, bool $scoped = false): void
    {
        $this->organization = $organization;
        $this->scoped = $scoped;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function isScoped(): bool
    {
        return $this->scoped;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->scoped = false;
    }
}
