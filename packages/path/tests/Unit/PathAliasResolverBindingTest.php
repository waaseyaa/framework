<?php

declare(strict_types=1);

namespace Waaseyaa\Path\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\Path\PathAliasResolver;

/**
 * Regression guard for #1518: PathAliasResolver::resolve() must call
 * accessCheck(false) — system-context URL-to-entity-ID lookup runs without
 * a request-scoped account; entity-level access is enforced by the caller
 * when the resolved entity is subsequently loaded.
 *
 * Without accessCheck(false), SqlEntityQuery::execute() throws
 * MissingQueryAccountException under the fail-closed default introduced in
 * v0.1.0-alpha.181, returning HTTP 500 on every path-aliased URL.
 */
#[CoversClass(PathAliasResolver::class)]
final class PathAliasResolverBindingTest extends TestCase
{
    #[Test]
    public function resolveCallsAccessCheckFalseForSystemContext(): void
    {
        $query = new RecordingEntityQuery();

        $resolver = new PathAliasResolver(new QueryOnlyStubRepository($query));
        $resolver->resolve('/any-alias');

        self::assertContains(
            false,
            $query->accessChecks,
            'PathAliasResolver::resolve() must call accessCheck(false) for system-context query (regression #1518).',
        );
    }
}
