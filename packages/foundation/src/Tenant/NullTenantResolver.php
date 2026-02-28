<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tenant;

final class NullTenantResolver implements TenantResolverInterface
{
    public function resolve(array $requestAttributes = []): ?string
    {
        return null;
    }
}
