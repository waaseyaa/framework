<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * A shared binding declared by a merged child must resolve to a SINGLE instance
 * across the merge root — including when it is consumed from inside another
 * binding's closure (which captures the child's $this).
 */
#[CoversClass(ServiceProvider::class)]
final class MergeChildProviderResolutionTest extends TestCase
{
    #[Test]
    public function intra_child_shared_consumption_yields_one_instance_via_the_parent(): void
    {
        $child = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('svc.a', static fn (): \stdClass => new \stdClass());
                $this->singleton('svc.b', fn (): MergeProbeConsumer => new MergeProbeConsumer($this->resolve('svc.a')));
            }
        };

        $parent = new class ($child) extends ServiceProvider {
            public function __construct(private readonly ServiceProvider $child) {}

            public function register(): void
            {
                $this->mergeChildProvider($this->child);
            }
        };
        $parent->register();

        $a = $parent->resolve('svc.a');
        $b = $parent->resolve('svc.b');
        self::assertInstanceOf(MergeProbeConsumer::class, $b);
        self::assertSame($a, $b->dependency, 'B must consume the same singleton A the parent resolves.');
    }

    #[Test]
    public function nested_grandchild_merge_holds_the_guarantee_through_the_ultimate_root(): void
    {
        $grandchild = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('svc.a', static fn (): \stdClass => new \stdClass());
                $this->singleton('svc.b', fn (): MergeProbeConsumer => new MergeProbeConsumer($this->resolve('svc.a')));
            }
        };

        $child = new class ($grandchild) extends ServiceProvider {
            public function __construct(private readonly ServiceProvider $grandchild) {}

            public function register(): void
            {
                $this->mergeChildProvider($this->grandchild);
            }
        };

        $root = new class ($child) extends ServiceProvider {
            public function __construct(private readonly ServiceProvider $child) {}

            public function register(): void
            {
                $this->mergeChildProvider($this->child);
            }
        };
        $root->register();

        $a = $root->resolve('svc.a');
        $b = $root->resolve('svc.b');
        self::assertInstanceOf(MergeProbeConsumer::class, $b);
        self::assertSame($a, $b->dependency, 'A grandchild singleton must be one instance through the ultimate root.');
    }

    #[Test]
    public function a_child_resolved_before_merge_is_adopted_not_split(): void
    {
        $child = new class extends ServiceProvider {
            public ?object $preResolvedA = null;

            public function register(): void
            {
                $this->singleton('svc.a', static fn (): \stdClass => new \stdClass());
                // Resolve during register() so the child holds a pre-merge $resolved entry.
                $this->preResolvedA = $this->resolve('svc.a');
                $this->singleton('svc.b', fn (): MergeProbeConsumer => new MergeProbeConsumer($this->resolve('svc.a')));
            }
        };

        $parent = new class ($child) extends ServiceProvider {
            public function __construct(private readonly ServiceProvider $child) {}

            public function register(): void
            {
                $this->mergeChildProvider($this->child);
            }
        };
        $parent->register();

        $a = $parent->resolve('svc.a');
        $b = $parent->resolve('svc.b');
        self::assertInstanceOf(MergeProbeConsumer::class, $b);
        self::assertSame($child->preResolvedA, $a, 'The pre-merge-resolved instance must be adopted, not replaced.');
        self::assertSame($a, $b->dependency, 'B must consume the adopted instance.');
    }

    #[Test]
    public function a_genuine_pre_resolved_conflict_fails_loudly(): void
    {
        $child = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('svc.a', static fn (): \stdClass => new \stdClass());
                $this->resolve('svc.a'); // child resolves its own A -> C
            }
        };

        $parent = new class ($child) extends ServiceProvider {
            public function __construct(private readonly ServiceProvider $child) {}

            public function register(): void
            {
                $this->singleton('svc.a', static fn (): \stdClass => new \stdClass());
                $this->resolve('svc.a'); // root resolves its own A -> R (R !== C)
                $this->mergeChildProvider($this->child);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('split-brain');
        $parent->register();
    }

    #[Test]
    public function merging_a_provider_into_itself_fails_loudly_instead_of_recursing(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->mergeChildProvider($this);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot merge itself');
        $provider->register();
    }
}

final class MergeProbeConsumer
{
    public function __construct(public readonly object $dependency) {}
}
