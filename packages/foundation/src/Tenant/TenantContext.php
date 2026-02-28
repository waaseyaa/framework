<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tenant;

final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function get(): ?string
    {
        return $this->tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function isActive(): bool
    {
        return $this->tenantId !== null;
    }
}
