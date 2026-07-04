<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\Seo\SitemapGenerator;

/**
 * Binding regression guard, superseding #1527 (R6/M3, audit M3).
 *
 * #1527 pinned that `SitemapGenerator::collectFromEntityTypes()` must call
 * `accessCheck(false)` for every entity-type query, because without it
 * `SqlEntityQuery::execute()` throws `MissingQueryAccountException` under the
 * fail-closed default (v0.1.0-alpha.181), returning HTTP 500 on
 * `/sitemap.xml`.
 *
 * That blanket bypass was itself a security hole (audit M3): it let a
 * PUBLISHED-but-access-restricted entity (a classification hold, a
 * genealogy privacy rule, etc.) be enumerated to anonymous crawlers, because
 * `accessCheck(false)` never consults an `AccessPolicyInterface`. The fix
 * replaces the blanket bypass with `setAccount($account)` — this pins the
 * NEW contract:
 *
 * - When a caller supplies an account, it must be bound via `setAccount()`
 *   (not `accessCheck(false)`) for every entity-type query, so the full
 *   `EntityAccessHandler` policy pipeline runs.
 * - When no account is supplied, the query is left at its fail-closed
 *   default (no `setAccount()`, no `accessCheck(false)`) — a caller building
 *   a public crawler surface MUST pass an account (typically
 *   `Waaseyaa\User\AnonymousUser`); omitting it is a caller bug that yields a
 *   500 (caught upstream by `SeoPublicController`, degrading to an empty
 *   document), not a silent leak.
 */
#[CoversClass(SitemapGenerator::class)]
final class SitemapGeneratorBindingTest extends TestCase
{
    #[Test]
    public function collectFromEntityTypesBindsTheAccountForEveryType(): void
    {
        $query = new RecordingEntityQuery();

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(EntityTypeInterface::class);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['node' => $def, 'media' => $def]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        $account = $this->createStub(AccountInterface::class);

        $gen = new SitemapGenerator();
        $gen->collectFromEntityTypes(
            $etm,
            static fn (string $type, int|string $id): string => 'https://example.com/' . $type . '/' . $id,
            account: $account,
        );

        self::assertSame(
            [],
            $query->accessChecks,
            'SitemapGenerator::collectFromEntityTypes() must NOT call accessCheck(false) when an account is supplied (R6/M3 supersedes #1527).',
        );
        self::assertSame(
            $account,
            $query->boundAccount,
            'SitemapGenerator::collectFromEntityTypes() must bind the caller-supplied account via setAccount() so access policies are enforced.',
        );
    }

    #[Test]
    public function collectFromEntityTypesLeavesTheQueryUnboundWithoutAnAccount(): void
    {
        // Fail-closed default: no account => no setAccount(), no
        // accessCheck(false). Reintroducing a blanket accessCheck(false)
        // fallback here would silently reopen the audit-M3 leak.
        $query = new RecordingEntityQuery();

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(EntityTypeInterface::class);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['node' => $def, 'media' => $def]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        $gen = new SitemapGenerator();
        $gen->collectFromEntityTypes(
            $etm,
            static fn (string $type, int|string $id): string => 'https://example.com/' . $type . '/' . $id,
        );

        self::assertSame([], $query->accessChecks);
        self::assertNull($query->boundAccount);
    }
}
