<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\ServiceProvider\CircularServiceResolutionException;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Discriminating tests for the circular-resolution guard in
 * {@see KernelHandlerContainer::get()} (#3021), site 2 of two: the
 * reflection auto-wiring branch's `$this->get($type->getName())` for
 * constructor parameters had no in-flight guard, so a constructor cycle
 * (A's ctor needs B, B's ctor needs A) recursed unboundedly. See the sibling
 * ServiceProvider test in `tests/Unit/ServiceProvider/` for site 1.
 */
#[CoversClass(KernelHandlerContainer::class)]
final class KernelHandlerContainerCircularResolutionTest extends TestCase
{
    #[Test]
    public function a_constructor_cycle_via_reflection_autowiring_throws_in_finite_time_with_an_ordered_diagnostic(): void
    {
        $container = new KernelHandlerContainer(providers: [], kernelBindings: []);

        try {
            $container->get(CircularCtorA::class);
            self::fail('A constructor cycle must throw.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame(
                sprintf(
                    'Circular service resolution detected: %s -> %s -> %s.',
                    CircularCtorA::class,
                    CircularCtorB::class,
                    CircularCtorA::class,
                ),
                $e->getMessage(),
            );
            self::assertSame([CircularCtorA::class, CircularCtorB::class, CircularCtorA::class], $e->cycle);
        }
    }

    #[Test]
    public function resolving_again_after_a_circular_failure_reproduces_the_same_failure_and_leaves_the_container_usable(): void
    {
        $container = new KernelHandlerContainer(providers: [], kernelBindings: []);

        $first = null;
        try {
            $container->get(CircularCtorA::class);
        } catch (CircularServiceResolutionException $e) {
            $first = $e->getMessage();
        }
        self::assertNotNull($first, 'First attempt must have thrown a circular failure.');

        // Same unrepaired classes, re-attempted: a stale in-flight entry
        // would either resolve nothing sensibly or report a different /
        // garbled cycle. It must reproduce the identical diagnostic.
        try {
            $container->get(CircularCtorA::class);
            self::fail('Expected the same circular failure again.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame($first, $e->getMessage());
        }

        // The container remains usable for an unrelated resolution afterwards.
        self::assertInstanceOf(\stdClass::class, $container->get(\stdClass::class));
    }

    /**
     * Compatibility obligation: KernelHandlerContainer::get() re-throws a
     * provider's \RuntimeException unless its message starts with the exact
     * "No binding registered for " prefix. A real (non-cycle) construction
     * failure must still propagate unwrapped with the guard in place.
     */
    #[Test]
    public function an_unrelated_construction_failure_from_a_provider_still_propagates_unchanged(): void
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

    /**
     * Requirement 6: the fall-through-to-not-found path for a genuinely
     * unbound, non-existent-class id is unchanged with the guard in place.
     */
    #[Test]
    public function a_genuinely_unbound_id_still_reports_not_found_with_the_guard_in_place(): void
    {
        $container = new KernelHandlerContainer(providers: [], kernelBindings: []);

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessageMatches('/totally\.unbound\.id/');

        $container->get('totally.unbound.id');
    }
}

final class CircularCtorA
{
    public function __construct(public CircularCtorB $b) {}
}

final class CircularCtorB
{
    public function __construct(public CircularCtorA $a) {}
}
