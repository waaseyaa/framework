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
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\MissingQueryAccountException;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Unit-test matrix for SqlEntityQuery access checking (mission
 * sql-entity-query-access-checking-01KRYP15, WP02 / T009).
 *
 * Pins:
 * - allow / deny / mixed filtering with a bound account (FR-001, FR-002)
 * - the C-004 `accessCheck(false)` bypass returns candidate IDs unfiltered
 * - the C-006 / FR-005 fail-closed throw on missing account
 * - count() returns post-filter cardinality when access checking is on,
 *   pre-filter cardinality when bypassed (FR-006)
 * - range() cursor advances by the unfiltered window (FR-007)
 * - anonymous account against a deny-all policy yields the empty set
 * - NFR-002: 25-row page check completes well under 100 ms
 */
#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryAccessCheckTest extends TestCase
{
    private DBALDatabase $database;

    private EntityRepository $repository;

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
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            $dispatcher,
            database: $this->database,
        );
    }

    #[Test]
    public function executeReturnsOnlyOwnedRows(): void
    {
        $accountA = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
            ['title' => 'b3', 'owner_id' => 2],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $query = $this->newQuery()
            ->withAccessHandler($handler)
            ->setAccount($accountA);

        $ids = $query->execute();

        $this->assertCount(2, $ids);
        $titles = $this->titlesFor($ids);
        $this->assertSame(['a1', 'a2'], $titles);
    }

    #[Test]
    public function executeAllowsBypassWithAccessCheckFalse(): void
    {
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
            ['title' => 'b3', 'owner_id' => 2],
        ]);

        // Bypass: no handler/loader wiring required, no account required.
        $ids = $this->newQuery()
            ->accessCheck(false)
            ->execute();

        $this->assertCount(5, $ids);
    }

    #[Test]
    public function executeThrowsWhenCheckEnabledAndAccountMissing(): void
    {
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
        ]);

        $query = $this->newQuery()
            ->withAccessHandler(new EntityAccessHandler());

        $this->expectException(MissingQueryAccountException::class);
        $this->expectExceptionMessageMatches('/access checking is enabled but no account is bound/');

        $query->execute();
    }

    #[Test]
    public function executeMixedAllowDenyDropsForbidden(): void
    {
        $accountA = $this->makeAccount(1);

        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['title' => 'row-' . $i, 'owner_id' => ($i % 2 === 0) ? 1 : 2];
        }
        $this->seedRows($rows);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $ids = $this->newQuery()
            ->withAccessHandler($handler)
            ->setAccount($accountA)
            ->sort('id', 'ASC')
            ->execute();

        // Even-indexed rows (row-2, row-4, row-6, row-8, row-10) are owned by 1.
        $this->assertCount(5, $ids);
        $this->assertSame(['row-2', 'row-4', 'row-6', 'row-8', 'row-10'], $this->titlesFor($ids));
    }

    #[Test]
    public function countReflectsPostFilterCardinality(): void
    {
        $accountA = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
            ['title' => 'b3', 'owner_id' => 2],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $result = $this->newQuery()
            ->withAccessHandler($handler)
            ->setAccount($accountA)
            ->count()
            ->execute();

        $this->assertSame([2], $result);
    }

    #[Test]
    public function countAccessCheckFalseReflectsPreFilterCardinality(): void
    {
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
            ['title' => 'b2', 'owner_id' => 2],
            ['title' => 'b3', 'owner_id' => 2],
        ]);

        $result = $this->newQuery()
            ->accessCheck(false)
            ->count()
            ->execute();

        $this->assertSame([5], $result);
    }

    #[Test]
    public function rangeCursorAdvancesByUnfilteredWindow(): void
    {
        $accountA = $this->makeAccount(1);

        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            // Even ids → owner 1 (visible to A), odd → owner 2 (forbidden).
            $rows[] = ['title' => 'row-' . $i, 'owner_id' => ($i % 2 === 0) ? 1 : 2];
        }
        $this->seedRows($rows);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $pages = [];
        foreach ([[0, 25], [25, 25], [50, 25], [75, 25]] as [$offset, $limit]) {
            $pages[] = $this->newQuery()
                ->withAccessHandler($handler)
                ->setAccount($accountA)
                ->sort('id', 'ASC')
                ->range($offset, $limit)
                ->execute();
        }

        $union = array_merge(...$pages);
        $unique = array_unique($union);

        // Survivors across the full 100-row table are the 50 even-indexed rows.
        $this->assertCount(50, $unique, 'union of paginated survivors equals full owned set');
        $this->assertCount(\count($union), $unique, 'pages are disjoint (no overlap across windows)');

        // Each page's survivors are bounded above by the window size (25) and
        // below by 0 — the filter never widens the window.
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(25, \count($page));
        }
    }

    #[Test]
    public function anonymousAccountSeesEmptyWhenPolicyForbidsAll(): void
    {
        $anonymous = $this->makeAccount(0);
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'a2', 'owner_id' => 2],
            ['title' => 'a3', 'owner_id' => 3],
        ]);

        $handler = new EntityAccessHandler();
        $handler->addPolicy(new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('deny-all test policy');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('deny-all test policy');
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        });

        $ids = $this->newQuery()
            ->withAccessHandler($handler)
            ->setAccount($anonymous)
            ->execute();

        $this->assertSame([], $ids);
    }

    #[Test]
    public function per25RowPageLatencyUnder100ms(): void
    {
        $accountA = $this->makeAccount(1);

        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['title' => 'row-' . $i, 'owner_id' => 1];
        }
        $this->seedRows($rows);

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->ownerOnlyPolicy());

        $start = microtime(true);
        $ids = $this->newQuery()
            ->withAccessHandler($handler)
            ->setAccount($accountA)
            ->range(0, 25)
            ->execute();
        $elapsed = microtime(true) - $start;

        $this->assertCount(25, $ids);
        $this->assertLessThan(
            0.1,
            $elapsed,
            sprintf('25-row access check should complete in < 100 ms, got %.3f ms', $elapsed * 1000),
        );
    }

    #[Test]
    public function executeWithEmptyHandlerDeniesEveryCandidateFailClosed(): void
    {
        // Deny-by-default (audit C-6): when access checking is enabled and an
        // account is bound but only an empty handler is wired, every candidate
        // resolves to Neutral and is dropped. The query layer fails closed —
        // it does NOT pass candidate IDs through unfiltered. (Pre-C-6 this
        // returned all candidate IDs open-by-default.)
        $accountA = $this->makeAccount(1);
        $this->seedRows([
            ['title' => 'a1', 'owner_id' => 1],
            ['title' => 'b1', 'owner_id' => 2],
        ]);

        $ids = $this->newQuery()
            ->withAccessHandler(new EntityAccessHandler())
            ->setAccount($accountA)
            ->execute();

        $this->assertCount(0, $ids, 'empty handler + deny-by-default drops every candidate (fail-closed)');
    }

    /**
     * Build a fresh query instance against the test storage.
     */
    private function newQuery(): SqlEntityQuery
    {
        $query = $this->repository->getQuery();
        \assert($query instanceof SqlEntityQuery);
        return $query;
    }

    /**
     * Seed rows into the storage.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function seedRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->repository->save($this->repository->create($row), validate: false);
        }
    }

    /**
     * Resolve titles for a list of IDs (used to assert ordering and selection).
     *
     * @param array<int, int|string> $ids
     * @return list<string>
     */
    private function titlesFor(array $ids): array
    {
        $entities = [];
        foreach ($this->repository->findMany($ids) as $entity) {
            $entityId = $entity->id();
            if ($entityId !== null) {
                $entities[$entityId] = $entity;
            }
        }
        $titles = [];
        foreach ($ids as $id) {
            $entity = $entities[$id] ?? null;
            if ($entity instanceof EntityInterface) {
                $title = $entity->get('title');
                $titles[] = is_string($title) ? $title : '';
            }
        }
        return $titles;
    }

    /**
     * Build a minimal AccountInterface implementation. PHPUnit's createMock()
     * cannot mock intersection types or stub permission lookups cleanly here,
     * so an anonymous class is the constitution-blessed pattern.
     */
    private function makeAccount(int $id): AccountInterface
    {
        return new AuthorizationPrincipal($id, $id !== 0, [], [], 'test-' . $id);
    }

    /**
     * Owner-only policy: Allowed when `entity.owner_id === account.id()`,
     * Forbidden otherwise. Used by the allow/deny/mixed scenarios.
     */
    private function ownerOnlyPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $ownerId = $entity->get('owner_id');
                if (\is_int($ownerId) && $ownerId === $account->id()) {
                    return AccessResult::allowed('owner match');
                }
                return AccessResult::forbidden('owner mismatch');
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
