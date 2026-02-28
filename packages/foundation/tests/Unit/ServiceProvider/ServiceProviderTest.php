<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\ServiceProvider;

use Aurora\Foundation\ServiceProvider\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceProvider::class)]
final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function register_records_singletons(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('FooInterface', 'FooImplementation');
            }
        };

        $provider->register();
        $bindings = $provider->getBindings();

        $this->assertArrayHasKey('FooInterface', $bindings);
        $this->assertSame('FooImplementation', $bindings['FooInterface']['concrete']);
        $this->assertTrue($bindings['FooInterface']['shared']);
    }

    #[Test]
    public function register_records_transient_bindings(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('BarInterface', 'BarImplementation');
            }
        };

        $provider->register();
        $bindings = $provider->getBindings();

        $this->assertFalse($bindings['BarInterface']['shared']);
    }

    #[Test]
    public function tags_are_recorded(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('FooInterface', 'FooImpl');
                $this->tag('FooInterface', 'aurora.managers');
            }
        };

        $provider->register();
        $tags = $provider->getTags();

        $this->assertArrayHasKey('aurora.managers', $tags);
        $this->assertContains('FooInterface', $tags['aurora.managers']);
    }

    #[Test]
    public function boot_is_optional(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };

        // boot() should not throw when not overridden
        $provider->boot();
        $this->assertTrue(true);
    }

    #[Test]
    public function provides_returns_empty_by_default(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };

        $this->assertSame([], $provider->provides());
    }

    #[Test]
    public function deferred_provider_declares_provided_interfaces(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('FooInterface', 'FooImpl');
            }

            public function provides(): array
            {
                return ['FooInterface'];
            }
        };

        $this->assertSame(['FooInterface'], $provider->provides());
        $this->assertTrue($provider->isDeferred());
    }

    #[Test]
    public function non_deferred_provider(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };

        $this->assertFalse($provider->isDeferred());
    }
}
