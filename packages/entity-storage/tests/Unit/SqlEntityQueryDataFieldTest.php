<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryDataFieldTest extends TestCase
{
    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'person',
            label: 'Person',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $database);
        $schemaHandler->ensureTable();

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($entityType, $database, $dispatcher);

        // 'mail' and 'role' are NOT table columns — they go into _data JSON.
        $this->storage->save($this->storage->create(['name' => 'Alice', 'mail' => 'alice@example.com', 'role' => 'admin']));
        $this->storage->save($this->storage->create(['name' => 'Bob', 'mail' => 'bob@example.com', 'role' => 'volunteer']));
        $this->storage->save($this->storage->create(['name' => 'Carol', 'mail' => 'carol@example.com', 'role' => 'volunteer']));
    }

    #[Test]
    public function conditionOnDataFieldFindsMatch(): void
    {
        $ids = $this->storage->getQuery()
            ->condition('mail', 'alice@example.com')
            ->execute();

        $this->assertCount(1, $ids);
    }

    #[Test]
    public function conditionOnDataFieldReturnsEmptyWhenNoMatch(): void
    {
        $ids = $this->storage->getQuery()
            ->condition('mail', 'nobody@example.com')
            ->execute();

        $this->assertSame([], $ids);
    }

    #[Test]
    public function conditionOnDataFieldWithMultipleResults(): void
    {
        $ids = $this->storage->getQuery()
            ->condition('role', 'volunteer')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function sortOnDataField(): void
    {
        $ids = $this->storage->getQuery()
            ->sort('mail', 'ASC')
            ->execute();

        // alice@ < bob@ < carol@ alphabetically
        $this->assertSame([1, 2, 3], $ids);
    }

    #[Test]
    public function sortOnDataFieldDescending(): void
    {
        $ids = $this->storage->getQuery()
            ->sort('mail', 'DESC')
            ->execute();

        $this->assertSame([3, 2, 1], $ids);
    }

    #[Test]
    public function containsOnDataField(): void
    {
        $ids = $this->storage->getQuery()
            ->condition('mail', 'bob', 'CONTAINS')
            ->execute();

        $this->assertCount(1, $ids);
    }

    #[Test]
    public function mixedColumnAndDataFieldConditions(): void
    {
        $ids = $this->storage->getQuery()
            ->condition('name', 'Bob')
            ->condition('role', 'volunteer')
            ->execute();

        $this->assertCount(1, $ids);
    }
}
