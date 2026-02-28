<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tenant;

final class TenantMiddleware
{
    public function __construct(
        private readonly TenantResolverInterface $resolver,
        private readonly TenantContext $context,
    ) {}

    /**
     * Process an incoming request and set the tenant context.
     *
     * @param array $requestAttributes Request attributes for tenant resolution.
     * @param callable $next The next middleware or handler.
     * @return mixed The response from the next handler.
     */
    public function handle(array $requestAttributes, callable $next): mixed
    {
        $tenantId = $this->resolver->resolve($requestAttributes);

        if ($tenantId !== null) {
            $this->context->set($tenantId);
        }

        try {
            return $next();
        } finally {
            $this->context->clear();
        }
    }
}
