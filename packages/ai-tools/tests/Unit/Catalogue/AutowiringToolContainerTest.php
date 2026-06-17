<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Catalogue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\Catalogue\AutowiringToolContainer;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

interface AutowireDepInterface {}

final class AutowireDep implements AutowireDepInterface {}

final class AutowireNoCtor {}

final class AutowireWithInterfaceDep
{
    public function __construct(public readonly AutowireDepInterface $dep) {}
}

final class AutowireWithNullableDep
{
    public function __construct(public readonly ?AutowireDepInterface $dep = null) {}
}

final class AutowireWithRequiredUnresolvable
{
    public function __construct(public readonly AutowireDepInterface $dep, public readonly string $required) {}
}

#[CoversClass(AutowiringToolContainer::class)]
final class AutowiringToolContainerTest extends TestCase
{
    private function provider(): ServiceProvider
    {
        return new class extends ServiceProvider {
            public function register(): void {}
        };
    }

    private function kernelServices(array $map): KernelServicesInterface
    {
        return new class ($map) implements KernelServicesInterface {
            /** @param array<string, object> $map */
            public function __construct(private readonly array $map) {}

            public function get(string $abstract): ?object
            {
                return $this->map[$abstract] ?? null;
            }
        };
    }

    #[Test]
    public function autowires_constructor_interface_dependency_from_the_bus(): void
    {
        $dep = new AutowireDep();
        $container = new AutowiringToolContainer(
            $this->kernelServices([AutowireDepInterface::class => $dep]),
            $this->provider(),
        );

        $instance = $container->get(AutowireWithInterfaceDep::class);

        self::assertInstanceOf(AutowireWithInterfaceDep::class, $instance);
        self::assertSame($dep, $instance->dep);
    }

    #[Test]
    public function instantiates_class_without_constructor(): void
    {
        $container = new AutowiringToolContainer($this->kernelServices([]), $this->provider());
        self::assertInstanceOf(AutowireNoCtor::class, $container->get(AutowireNoCtor::class));
    }

    #[Test]
    public function unresolved_nullable_dependency_becomes_null(): void
    {
        // Interface not on the bus, not instantiable -> nullable param resolves null.
        $container = new AutowiringToolContainer($this->kernelServices([]), $this->provider());
        $instance = $container->get(AutowireWithNullableDep::class);

        self::assertInstanceOf(AutowireWithNullableDep::class, $instance);
        self::assertNull($instance->dep);
    }

    #[Test]
    public function bus_resolution_wins_over_autowiring(): void
    {
        // When the requested id itself is on the bus, return it directly.
        $prebuilt = new AutowireNoCtor();
        $container = new AutowiringToolContainer(
            $this->kernelServices([AutowireNoCtor::class => $prebuilt]),
            $this->provider(),
        );

        self::assertSame($prebuilt, $container->get(AutowireNoCtor::class));
    }

    #[Test]
    public function required_unresolvable_dependency_throws(): void
    {
        $container = new AutowiringToolContainer(
            $this->kernelServices([AutowireDepInterface::class => new AutowireDep()]),
            $this->provider(),
        );

        $this->expectException(\RuntimeException::class);
        $container->get(AutowireWithRequiredUnresolvable::class);
    }

    #[Test]
    public function has_reflects_resolvability(): void
    {
        $container = new AutowiringToolContainer($this->kernelServices([]), $this->provider());
        self::assertTrue($container->has(AutowireNoCtor::class));
        self::assertFalse($container->has('Waaseyaa\\AI\\Tools\\Tests\\DoesNotExist'));
    }
}
