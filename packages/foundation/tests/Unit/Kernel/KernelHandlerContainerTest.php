<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(KernelHandlerContainer::class)]
final class KernelHandlerContainerTest extends TestCase
{
    #[Test]
    public function implements_psr11_container_interface(): void
    {
        $container = new KernelHandlerContainer([], []);

        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    #[Test]
    public function resolves_kernel_binding(): void
    {
        $sentinel = new \stdClass();
        $container = new KernelHandlerContainer(
            providers: [],
            kernelBindings: [
                'my.service' => static fn(ContainerInterface $c) => $sentinel,
            ],
        );

        $this->assertSame($sentinel, $container->get('my.service'));
    }

    #[Test]
    public function kernel_binding_is_cached(): void
    {
        $callCount = 0;
        $container = new KernelHandlerContainer(
            providers: [],
            kernelBindings: [
                'cached.service' => static function (ContainerInterface $c) use (&$callCount): \stdClass {
                    $callCount++;

                    return new \stdClass();
                },
            ],
        );

        $first = $container->get('cached.service');
        $second = $container->get('cached.service');

        $this->assertSame($first, $second);
        $this->assertSame(1, $callCount);
    }

    /**
     * #2820. A provider bound the handler, but its factory failed to resolve
     * one of ITS dependencies. That surfaces as "No binding registered for
     * <dependency>" — the same prefix an unbound id uses — so the container
     * fell through to reflection auto-wiring and reported the constructor
     * parameter reflection tripped over ("$projectRoot") instead of the
     * dependency that actually broke. The dependency failure must now be
     * chained and named.
     */
    #[Test]
    public function auto_wire_failure_names_the_provider_factory_dependency_that_broke(): void
    {
        $handler = new class ('/srv/app') {
            public function __construct(public readonly string $projectRoot) {}
        };
        $handlerClass = $handler::class;

        $provider = new class ($handlerClass) extends ServiceProvider {
            public function __construct(private readonly string $handlerClass) {}

            public function register(): void {}

            public function resolve(string $abstract): object
            {
                if ($abstract === $this->handlerClass) {
                    // The factory ran and failed on a dependency the bus does
                    // not serve — exactly ServiceProvider::resolve()'s signal.
                    throw new \RuntimeException('No binding registered for Acme\Diagnostic\CheckerInterface.');
                }

                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $container = new KernelHandlerContainer(providers: [$provider], kernelBindings: []);

        try {
            $container->get($handlerClass);
            self::fail('Auto-wiring a string parameter must fail.');
        } catch (NotFoundExceptionInterface $e) {
            self::assertStringContainsString('unresolvable parameter "$projectRoot"', $e->getMessage());
            self::assertStringContainsString(
                'A provider binding exists but its factory failed first: No binding registered for Acme\Diagnostic\CheckerInterface.',
                $e->getMessage(),
            );
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertSame('No binding registered for Acme\Diagnostic\CheckerInterface.', $e->getPrevious()->getMessage());
        }
    }

    #[Test]
    public function genuinely_unbound_id_reports_no_factory_failure(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}

            public function resolve(string $abstract): object
            {
                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $container = new KernelHandlerContainer(providers: [$provider], kernelBindings: []);

        try {
            $container->get('totally.unbound.id');
            self::fail('An unbound non-class id must not resolve.');
        } catch (NotFoundExceptionInterface $e) {
            self::assertSame('No binding for "totally.unbound.id" in KernelHandlerContainer.', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function resolves_provider_binding(): void
    {
        $sentinel = new \stdClass();
        $provider = new class ($sentinel) extends ServiceProvider {
            public function __construct(private readonly object $instance) {}

            public function register(): void {}

            public function resolve(string $abstract): object
            {
                if ($abstract === 'provider.service') {
                    return $this->instance;
                }
                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $container = new KernelHandlerContainer(providers: [$provider], kernelBindings: []);

        $this->assertSame($sentinel, $container->get('provider.service'));
    }

    #[Test]
    public function kernel_binding_takes_precedence_over_provider(): void
    {
        $kernelInstance = new \stdClass();
        $providerInstance = new \stdClass();

        $provider = new class ($providerInstance) extends ServiceProvider {
            public function __construct(private readonly object $instance) {}

            public function register(): void {}

            public function resolve(string $abstract): object
            {
                if ($abstract === 'shared.service') {
                    return $this->instance;
                }
                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $container = new KernelHandlerContainer(
            providers: [$provider],
            kernelBindings: [
                'shared.service' => static fn(ContainerInterface $c) => $kernelInstance,
            ],
        );

        $this->assertSame($kernelInstance, $container->get('shared.service'));
    }

    #[Test]
    public function autowires_concrete_class_with_no_constructor(): void
    {
        $container = new KernelHandlerContainer(providers: [], kernelBindings: []);

        $instance = $container->get(\stdClass::class);

        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    #[Test]
    public function throws_not_found_for_unknown_abstract_id(): void
    {
        $container = new KernelHandlerContainer(providers: [], kernelBindings: []);

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessageMatches('/totally\.unknown\.abstract/');

        $container->get('totally.unknown.abstract');
    }

    #[Test]
    public function has_returns_true_for_resolvable_id(): void
    {
        $container = new KernelHandlerContainer(
            providers: [],
            kernelBindings: [
                'exists.service' => static fn(ContainerInterface $c) => new \stdClass(),
            ],
        );

        $this->assertTrue($container->has('exists.service'));
    }

    #[Test]
    public function has_returns_false_for_unresolvable_id(): void
    {
        $container = new KernelHandlerContainer(providers: [], kernelBindings: []);

        $this->assertFalse($container->has('no.such.binding'));
    }

    #[Test]
    public function real_construction_error_from_provider_propagates(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}

            public function resolve(string $abstract): object
            {
                if ($abstract === 'boom.service') {
                    throw new \LogicException('construction blew up');
                }
                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $container = new KernelHandlerContainer(providers: [$provider], kernelBindings: []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('construction blew up');

        $container->get('boom.service');
    }
}
