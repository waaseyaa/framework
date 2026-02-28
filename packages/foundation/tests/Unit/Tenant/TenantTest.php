<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Tenant;

use Aurora\Foundation\Tenant\NullTenantResolver;
use Aurora\Foundation\Tenant\TenantContext;
use Aurora\Foundation\Tenant\TenantMiddleware;
use Aurora\Foundation\Tenant\TenantResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantContext::class)]
#[CoversClass(NullTenantResolver::class)]
#[CoversClass(TenantMiddleware::class)]
final class TenantTest extends TestCase
{
    #[Test]
    public function null_tenant_resolver_returns_null(): void
    {
        $resolver = new NullTenantResolver();

        $this->assertNull($resolver->resolve());
        $this->assertNull($resolver->resolve(['host' => 'example.com']));
    }

    #[Test]
    public function tenant_context_stores_and_retrieves_tenant(): void
    {
        $context = new TenantContext();

        $this->assertNull($context->get());
        $this->assertFalse($context->isActive());

        $context->set('acme');
        $this->assertSame('acme', $context->get());
        $this->assertTrue($context->isActive());

        $context->clear();
        $this->assertNull($context->get());
        $this->assertFalse($context->isActive());
    }

    #[Test]
    public function tenant_middleware_sets_context_from_resolver(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn('acme');

        $context = new TenantContext();
        $middleware = new TenantMiddleware($resolver, $context);

        $capturedTenant = null;
        $middleware->handle(['host' => 'acme.example.com'], function () use ($context, &$capturedTenant) {
            $capturedTenant = $context->get();
            return 'response';
        });

        $this->assertSame('acme', $capturedTenant);
        // Context cleared after middleware completes
        $this->assertNull($context->get());
    }

    #[Test]
    public function tenant_middleware_clears_context_on_exception(): void
    {
        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn('acme');

        $context = new TenantContext();
        $middleware = new TenantMiddleware($resolver, $context);

        try {
            $middleware->handle([], function () {
                throw new \RuntimeException('handler error');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // Context must be cleared even on exception
        $this->assertNull($context->get());
    }

    #[Test]
    public function tenant_middleware_with_null_resolver_does_not_set_context(): void
    {
        $resolver = new NullTenantResolver();
        $context = new TenantContext();
        $middleware = new TenantMiddleware($resolver, $context);

        $capturedTenant = null;
        $middleware->handle([], function () use ($context, &$capturedTenant) {
            $capturedTenant = $context->get();
            return 'response';
        });

        $this->assertNull($capturedTenant);
    }

    #[Test]
    public function tenant_middleware_returns_handler_response(): void
    {
        $resolver = new NullTenantResolver();
        $context = new TenantContext();
        $middleware = new TenantMiddleware($resolver, $context);

        $result = $middleware->handle([], fn () => 'hello');

        $this->assertSame('hello', $result);
    }
}
