<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityQueryAccessCheck;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * T019 — Integration: `accessCheck(false)` returns all rows in a system
 * context, including the row count, even when no account is bound.
 *
 * Covers SC-003 (system context bypass) and FR-002 / FR-006 of mission
 * `sql-entity-query-access-checking-01KRYP15`.
 *
 * The point of this test is to prove that the bypass is a real, named,
 * audited opt-out that does not depend on:
 *  - having a handler bound;
 *  - having a loader bound;
 *  - having an account bound.
 *
 * This is the contract the reaper, purge job, and migration platform
 * depend on.
 */
#[CoversNothing]
final class BypassRespectsSystemContextTest extends TestCase
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

        new SqlSchemaHandler($entityType, $this->database)->ensureTable();

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            new EventDispatcher(),
            database: $this->database,
        );

        // Seed 5 rows owned by three different (fictional) accounts.
        $rows = [
            ['title' => 'r1', 'owner_id' => 1],
            ['title' => 'r2', 'owner_id' => 1],
            ['title' => 'r3', 'owner_id' => 2],
            ['title' => 'r4', 'owner_id' => 3],
            ['title' => 'r5', 'owner_id' => 3],
        ];
        foreach ($rows as $row) {
            $this->repository->save($this->repository->create($row), validate: false);
        }
    }

    #[Test]
    public function bypassExecuteReturnsAllRows(): void
    {
        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->execute();

        $this->assertCount(5, $ids, 'accessCheck(false) returns every row in the table');
    }

    #[Test]
    public function bypassCountReturnsTotalRowCount(): void
    {
        $result = $this->repository->getQuery()
            ->accessCheck(false)
            ->count()
            ->execute();

        $this->assertSame([5], $result, 'accessCheck(false)->count() returns pre-filter cardinality');
    }

    #[Test]
    public function bypassWithoutBoundAccountDoesNotThrow(): void
    {
        // FR-003 / C-006: the throw on missing account is for
        // accessCheck(true). When access checking is explicitly disabled,
        // the absence of an account is benign — the reaper, purge job, and
        // migration platform run in a system context and have no principal
        // to bind.
        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->execute();

        $this->assertCount(5, $ids);
    }
}
