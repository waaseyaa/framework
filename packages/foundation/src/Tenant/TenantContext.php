<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tenant;

/**
 * @internal Not wired in v1.0 — reserved for v2.0 multi-tenant support.
 */
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
