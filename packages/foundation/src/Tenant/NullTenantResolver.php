<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tenant;

/**
 * @internal Not wired in v1.0 — reserved for v2.0 multi-tenant support.
 */
final class NullTenantResolver implements TenantResolverInterface
{
    public function resolve(array $requestAttributes = []): ?string
    {
        return null;
    }
}
