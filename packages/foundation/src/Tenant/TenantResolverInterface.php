<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tenant;

/**
 * @internal Not wired in v1.0 — reserved for v2.0 multi-tenant support.
 */
interface TenantResolverInterface
{
    /**
     * Resolve the tenant ID from the current request context.
     *
     * @return string|null The tenant ID, or null if no tenant can be resolved.
     */
    public function resolve(array $requestAttributes = []): ?string;
}
