<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\ServiceProvider;

use Aurora\Foundation\ServiceProvider\ContainerCompiler;
use Aurora\Foundation\ServiceProvider\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ContainerCompiler::class)]
final class ContainerCompilerTest extends TestCase
{
    #[Test]
    public function compiles_singleton_bindings(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);
        $container->compile();

        $this->assertTrue($container->has(\DateTimeInterface::class));
    }

    #[Test]
    public function compiles_transient_bindings(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind(\DateTimeInterface::class, \DateTimeImmutable::class);
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);
        $container->compile();

        $this->assertTrue($container->has(\DateTimeInterface::class));
        $def = $container->getDefinition(\DateTimeInterface::class);
        $this->assertFalse($def->isShared());
    }

    #[Test]
    public function compiles_tagged_services(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
                $this->tag(\DateTimeInterface::class, 'aurora.time');
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);

        $tagged = $container->findTaggedServiceIds('aurora.time');
        $this->assertArrayHasKey(\DateTimeInterface::class, $tagged);
    }

    #[Test]
    public function calls_register_then_boot_in_order(): void
    {
        $order = [];
        $provider = new class($order) extends ServiceProvider {
            public function __construct(private array &$order) {}

            public function register(): void
            {
                $this->order[] = 'register';
                $this->singleton('Foo', \stdClass::class);
            }

            public function boot(): void
            {
                $this->order[] = 'boot';
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);

        $this->assertSame(['register', 'boot'], $order);
    }
}
