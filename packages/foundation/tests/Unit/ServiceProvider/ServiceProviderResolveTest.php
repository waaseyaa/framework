<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(ServiceProvider::class)]
final class ServiceProviderResolveTest extends TestCase
{
    #[Test]
    public function resolve_singleton_returns_same_instance(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\stdClass::class, fn() => new \stdClass());
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $a = $provider->resolvePublic(\stdClass::class);
        $b = $provider->resolvePublic(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $a);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function resolve_bind_returns_new_instance_each_time(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind(\stdClass::class, fn() => new \stdClass());
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $a = $provider->resolvePublic(\stdClass::class);
        $b = $provider->resolvePublic(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $a);
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function resolve_throws_for_unknown_binding(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No binding registered for');
        $provider->resolvePublic('Nonexistent\\Class');
    }

    #[Test]
    public function resolve_with_string_concrete_instantiates_class(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\stdClass::class, \stdClass::class);
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $this->assertInstanceOf(\stdClass::class, $provider->resolvePublic(\stdClass::class));
    }

    #[Test]
    public function commands_can_use_resolve_for_dependencies(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('test.service', fn() => new \stdClass());
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $service = $provider->resolvePublic('test.service');
        $this->assertInstanceOf(\stdClass::class, $service);
    }

    #[Test]
    public function resolve_falls_back_to_kernel_resolver_for_unbound_service(): void
    {
        $kernelService = new \stdClass();
        $kernelService->origin = 'kernel';

        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };
        $provider->register();
        $provider->setKernelResolver(function (string $className) use ($kernelService): ?object {
            return $className === \stdClass::class ? $kernelService : null;
        });

        $resolved = $provider->resolve(\stdClass::class);
        $this->assertSame($kernelService, $resolved);
    }

    #[Test]
    public function resolve_throws_when_kernel_resolver_also_returns_null(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };
        $provider->register();
        $provider->setKernelResolver(fn(string $className): ?object => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No binding registered for');
        $provider->resolve('Nonexistent\\Service');
    }
}
