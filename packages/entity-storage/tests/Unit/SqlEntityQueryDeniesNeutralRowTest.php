<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Pins the Layer-3 (entity-query) access semantics declared in
 * docs/specs/access-control.md after audit finding C-6: the survivor test is
 * `isAllowed()` (DENY-BY-DEFAULT). A NEUTRAL access result (no policy opined, or
 * a policy declined without forbidding) DROPS the row; only Allowed survives.
 *
 * This makes the query layer consistent with the entity gate and every
 * serializing consumer (JsonApiController::index + accessFilteredTotal,
 * GraphQlAccessGuard::canView, ai-vector SearchController, mcp tools) — all of
 * which already re-filter with `isAllowed()`. The previous open-by-default
 * (`!isForbidden()`) survivor test admitted Neutral rows at the query/count
 * layer, relying entirely on downstream consumers to re-deny them; the next
 * consumer that trusted `getQuery()->execute()` or `->count()` without
 * re-filtering would have re-opened a cardinality/existence leak (C-6) with no
 * test to catch it. The query layer now denies by default itself.
 *
 * History: #1714 threaded the real composed access handler into
 * `SqlEntityStorage::getQuery()`; C-6 (this change) flips the survivor predicate
 * so the query layer enforces deny-by-default rather than passing a Neutral
 * candidate window through. The empty-handler / no-loader fallback is now
 * fail-closed (returns nothing) instead of an unfiltered pass-through.
 *
 * If anyone reverts the survivor test to `!isForbidden()`, these assertions fail
 * loudly and force a spec change (access-control.md) BEFORE the semantics can
 * change.
 */
#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryDeniesNeutralRowTest extends TestCase
{
    private DBALDatabase $database;

    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($entityType, $this->database, $dispatcher);
    }

    #[Test]
    public function neutralResultIsDeniedAtQueryLayer(): void
    {
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'n1', 'owner_id' => 1],
            ['title' => 'n2', 'owner_id' => 2],
            ['title' => 'n3', 'owner_id' => 3],
        ]);

        // A policy that NEVER forbids and NEVER allows — every row resolves to
        // Neutral. Deny-by-default drops all of them: a Neutral result is not a
        // grant. Under the pre-C-6 `!isForbidden()` survivor test this returned
        // all three rows (the leak).
        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->alwaysNeutralPolicy());

        $ids = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->sort('id', 'ASC')
            ->execute();

        $this->assertCount(0, $ids, 'Neutral access results must be DENIED at the query layer (deny-by-default, access-control.md Layer 3 / C-6).');
    }

    #[Test]
    public function neutralResultIsNotCountedAtQueryLayer(): void
    {
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'n1', 'owner_id' => 1],
            ['title' => 'n2', 'owner_id' => 2],
            ['title' => 'n3', 'owner_id' => 3],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->alwaysNeutralPolicy());

        $result = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->count()
            ->execute();

        // count() shares filterCandidates(); deny-by-default means Neutral rows
        // are not counted. The pre-C-6 survivor test returned [3] here, leaking
        // restricted-collection cardinality.
        $this->assertSame([0], $result, 'count() must exclude Neutral rows (deny-by-default query layer).');
    }

    #[Test]
    public function allowedResultSurvivesAtQueryLayer(): void
    {
        // Positive case: the flip must not over-deny. An owner-only policy that
        // returns Allowed for the acting account's rows and Forbidden otherwise
        // yields exactly the account's own rows.
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'mine-a', 'owner_id' => 1],
            ['title' => 'theirs', 'owner_id' => 2],
            ['title' => 'mine-b', 'owner_id' => 1],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $ids = $this->newQuery()
            ->withAccessHandler($handler)
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->sort('id', 'ASC')
            ->execute();

        $this->assertCount(2, $ids, 'Allowed rows must survive — deny-by-default must not over-deny granted rows.');
    }

    #[Test]
    public function emptyHandlerDeniesEveryRow(): void
    {
        // The empty-handler fallback returns Neutral for every entity. Under
        // deny-by-default that drops every row — an access-checked query against
        // an unwired handler returns nothing (fail-closed), never the unfiltered
        // candidate set. Pre-C-6 this admitted every row (open-by-default).
        $account = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'x1', 'owner_id' => 1],
            ['title' => 'x2', 'owner_id' => 2],
        ]);

        $ids = $this->newQuery()
            ->withAccessHandler(new EntityAccessHandler())
            ->withEntityLoader($this->storage->loadMultiple(...))
            ->setAccount($account)
            ->execute();

        $this->assertCount(0, $ids, 'An empty (no-policy) handler returns Neutral, which deny-by-default drops — fail-closed.');
    }

    #[Test]
    public function productionStorageWithoutHandlerIsFailClosed(): void
    {
        // PRODUCTION reality post-C-6: when storage is built without a wired
        // access handler (the resolver returns null — e.g. a direct construction
        // or a misconfigured factory), getQuery() falls back to an empty handler
        // and an access-checked query DENIES every candidate. The query layer is
        // fail-closed, not an open candidate window. (The kernel wires the real
        // composed handler at boot, so genuine HTTP reads enforce real policies.)
        $unprivileged = $this->makeAccount(42);
        $this->seedRows([
            ['title' => 'p1', 'owner_id' => 1],
            ['title' => 'p2', 'owner_id' => 2],
        ]);

        $query = $this->storage->getQuery();
        $query->setAccount($unprivileged);
        $ids = $query->execute();

        self::assertCount(
            0,
            $ids,
            'Production storage with no wired handler must deny every candidate (fail-closed), not pass them through.',
        );
    }

    private function newQuery(): SqlEntityQuery
    {
        $query = $this->storage->getQuery();
        \assert($query instanceof SqlEntityQuery);

        return $query;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->storage->save($this->storage->create($row));
        }
    }

    private function makeAccount(int $id): AccountInterface
    {
        return new class($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return $this->accountId !== 0;
            }
        };
    }

    /**
     * Policy that opines Neutral on every operation — never Allowed, never
     * Forbidden. Exercises the Neutral-denied branch the spec mandates.
     */
    private function alwaysNeutralPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral('no opinion');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }
        };
    }

    /**
     * Owner-only view policy: Allowed when the row's owner_id matches the acting
     * account, Forbidden otherwise. Proves the deny-by-default flip keeps granted
     * rows.
     */
    private function ownerOnlyPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $ownerId = $entity->get('owner_id');

                return is_int($ownerId) && $ownerId === $account->id()
                    ? AccessResult::allowed('owner match')
                    : AccessResult::forbidden('owner mismatch');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }
        };
    }
}
