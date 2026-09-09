<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\CircularServiceResolutionException;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Discriminating tests for the circular-resolution guard in
 * {@see ServiceProvider::resolve()} (#3021). Site 1 of two: the second guard
 * lives in {@see \Waaseyaa\Foundation\Kernel\KernelHandlerContainer::get()}
 * (see the sibling test in `tests/Unit/Kernel/`).
 */
#[CoversClass(ServiceProvider::class)]
#[CoversClass(CircularServiceResolutionException::class)]
final class CircularServiceResolutionTest extends TestCase
{
    #[Test]
    public function direct_self_resolution_throws_in_finite_time_and_names_the_abstract(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('A', fn () => $this->resolve('A'));
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('A');
            self::fail('Direct self-resolution must throw.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame('Circular service resolution detected: A -> A.', $e->getMessage());
            self::assertSame(['A', 'A'], $e->cycle);
        }
    }

    #[Test]
    public function numeric_string_abstract_keeps_a_string_only_cycle_contract(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('0', fn () => $this->resolve('0'));
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('0');
            self::fail('Numeric-string self-resolution must throw.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame(['0', '0'], $e->cycle);
            self::assertSame('Circular service resolution detected: 0 -> 0.', $e->getMessage());
        }
    }

    #[Test]
    public function multi_service_cycle_reports_the_exact_ordered_path(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('A', fn () => $this->resolve('B'));
                $this->bind('B', fn () => $this->resolve('C'));
                $this->bind('C', fn () => $this->resolve('A'));
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('A');
            self::fail('A multi-service cycle must throw.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame('Circular service resolution detected: A -> B -> C -> A.', $e->getMessage());
            self::assertSame(['A', 'B', 'C', 'A'], $e->cycle);
        }
    }

    /**
     * The in-flight guard must live on the merge root (private $mergeRoot
     * link), not per-provider, or a cycle that crosses a
     * {@see ServiceProvider::mergeChildProvider()} boundary would be missed:
     * A (parent/root) -> B (parent) -> C (merged child) -> A (back through
     * the root).
     */
    #[Test]
    public function a_cycle_crossing_a_merge_child_provider_root_is_still_caught(): void
    {
        $child = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('B', fn () => $this->resolve('C'));
                $this->bind('C', fn () => $this->resolve('A'));
            }
        };

        $parent = new class ($child) extends ServiceProvider {
            public function __construct(private readonly ServiceProvider $child) {}

            public function register(): void
            {
                $this->bind('A', fn () => $this->resolve('B'));
                $this->mergeChildProvider($this->child);
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $parent->register();

        try {
            $parent->resolvePublic('A');
            self::fail('A cycle crossing the merge root must throw.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame('Circular service resolution detected: A -> B -> C -> A.', $e->getMessage());
        }
    }

    /**
     * False-positive guard: X and Y both legitimately depend on a shared
     * singleton S, resolved from a root that needs both. A naive
     * "already seen this abstract, ever" set (without clearing after a
     * successful resolution) would misreport this diamond as a cycle when Y
     * resolves S the second time. The in-flight set must be cleared as soon
     * as S's own factory returns, and S's cached singleton entry must then
     * satisfy Y directly without ever touching the in-flight set again.
     */
    #[Test]
    public function a_diamond_shared_dependency_is_not_a_cycle_and_yields_the_same_singleton(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('S', static fn () => new \stdClass());
                $this->bind('X', fn () => (object) ['s' => $this->resolve('S')]);
                $this->bind('Y', fn () => (object) ['s' => $this->resolve('S')]);
                $this->bind('Root', fn () => (object) ['x' => $this->resolve('X'), 'y' => $this->resolve('Y')]);
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $root = $provider->resolvePublic('Root');

        self::assertSame($root->x->s, $root->y->s);
    }

    #[Test]
    public function unrelated_logic_exception_from_a_factory_propagates_unchanged(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('Boom', function (): never {
                    throw new \LogicException('boom logic');
                });
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('boom logic');
        $provider->resolvePublic('Boom');
    }

    #[Test]
    public function unrelated_runtime_exception_from_a_factory_propagates_unchanged_and_is_not_wrapped(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('Boom', function (): never {
                    throw new \RuntimeException('boom runtime');
                });
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('Boom');
            self::fail('Expected the RuntimeException to propagate.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(CircularServiceResolutionException::class, $e);
            self::assertSame(\RuntimeException::class, $e::class);
            self::assertSame('boom runtime', $e->getMessage());
        }
    }

    #[Test]
    public function resolving_again_after_a_circular_failure_reproduces_the_same_failure_and_leaves_the_provider_usable(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('A', fn () => $this->resolve('A'));
                $this->singleton('Other', static fn () => new \stdClass());
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $first = null;
        try {
            $provider->resolvePublic('A');
        } catch (CircularServiceResolutionException $e) {
            $first = $e->getMessage();
        }
        self::assertNotNull($first, 'First attempt must have thrown a circular failure.');

        // Same unrepaired binding, re-attempted: a stale in-flight entry
        // would either throw nothing (silently "resolved" garbage) or throw
        // a different/garbled cycle. It must reproduce the identical
        // diagnostic.
        try {
            $provider->resolvePublic('A');
            self::fail('Expected the same circular failure again.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame($first, $e->getMessage());
        }

        // An unrelated abstract still resolves normally afterwards.
        self::assertInstanceOf(\stdClass::class, $provider->resolvePublic('Other'));
    }

    #[Test]
    public function resolving_again_after_an_unrelated_factory_exception_leaves_no_stale_in_flight_state(): void
    {
        $provider = new class extends ServiceProvider {
            public bool $shouldThrow = true;

            public function register(): void
            {
                $this->bind('Flaky', function () {
                    if ($this->shouldThrow) {
                        throw new \LogicException('flaky boom');
                    }

                    return new \stdClass();
                });
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('Flaky');
            self::fail('Expected the factory to throw on the first attempt.');
        } catch (\LogicException $e) {
            self::assertSame('flaky boom', $e->getMessage());
        }

        $provider->shouldThrow = false;
        self::assertInstanceOf(\stdClass::class, $provider->resolvePublic('Flaky'));
    }

    /**
     * Requirement 6: adding the in-flight guard must not change the existing
     * "unbound abstract" surface. resolve() still throws the exact canonical
     * message and resolveOptional() still returns null — never the new
     * circular-resolution type for a genuinely unbound abstract.
     */
    #[Test]
    public function an_unbound_abstract_still_reports_no_binding_registered_and_resolve_optional_still_returns_null(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('Nonexistent\\Service');
            self::fail('Expected a RuntimeException for an unbound abstract.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(CircularServiceResolutionException::class, $e);
            self::assertSame('No binding registered for Nonexistent\\Service.', $e->getMessage());
        }

        self::assertNull($provider->resolveOptional('Nonexistent\\Service'));
    }

    /**
     * The diagnostic is restricted to abstract identifiers. A value captured
     * by a factory is not an identifier and must never leak into the exception
     * message or the structured $cycle property, no matter how
     * deep the cycle recurses before the guard fires.
     */
    #[Test]
    public function the_circular_diagnostic_never_discloses_factory_closure_secrets(): void
    {
        $secret = 'sk-super-secret-token-must-never-leak-9f3c2a';

        $provider = new class ($secret) extends ServiceProvider {
            public function __construct(private readonly string $secret) {}

            public function register(): void
            {
                $this->bind('A', function () {
                    // Closes over a secret-looking value that must never
                    // surface in the circular-resolution diagnostic.
                    $carrier = (object) ['token' => $this->secret];
                    unset($carrier);

                    return $this->resolve('A');
                });
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic('A');
            self::fail('Expected a circular resolution failure.');
        } catch (CircularServiceResolutionException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, implode(' ', $e->cycle));
            self::assertSame(['A', 'A'], $e->cycle);
        }
    }

    #[Test]
    public function caller_controlled_identifier_text_is_preserved_for_diagnosis(): void
    {
        $identifier = "tenant-token\nsecond-line";

        $provider = new class ($identifier) extends ServiceProvider {
            public function __construct(private readonly string $identifier) {}

            public function register(): void
            {
                $this->bind($this->identifier, fn () => $this->resolve($this->identifier));
            }

            public function resolvePublic(string $abstract): object
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        try {
            $provider->resolvePublic($identifier);
            self::fail('Caller-controlled identifier self-resolution must throw.');
        } catch (CircularServiceResolutionException $e) {
            self::assertSame([$identifier, $identifier], $e->cycle);
            self::assertStringContainsString($identifier, $e->getMessage());
        }
    }
}
