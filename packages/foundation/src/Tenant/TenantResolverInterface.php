<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tenant;

interface TenantResolverInterface
{
    /**
     * Resolve the tenant ID from the current request context.
     *
     * @return string|null The tenant ID, or null if no tenant can be resolved.
     */
    public function resolve(array $requestAttributes = []): ?string;
}
