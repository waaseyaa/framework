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
final class SqlEntityQueryLikeTest extends TestCase
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
    public function containsOperatorMatchesSubstring(): void
    {
        $this->storage->save($this->storage->create(['title' => 'Hello World']));
        $this->storage->save($this->storage->create(['title' => 'Goodbye Moon']));
        $this->storage->save($this->storage->create(['title' => 'World Peace']));

        $ids = $this->storage->getQuery()
            ->condition('title', 'World', 'CONTAINS')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function startsWithOperatorMatchesPrefix(): void
    {
        $this->storage->save($this->storage->create(['title' => 'Hello World']));
        $this->storage->save($this->storage->create(['title' => 'Goodbye Moon']));
        $this->storage->save($this->storage->create(['title' => 'Hello Again']));

        $ids = $this->storage->getQuery()
            ->condition('title', 'Hello', 'STARTS_WITH')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function containsOperatorIsCaseInsensitive(): void
    {
        $this->storage->save($this->storage->create(['title' => 'Hello World']));
        $this->storage->save($this->storage->create(['title' => 'hello world']));

        $ids = $this->storage->getQuery()
            ->condition('title', 'hello', 'CONTAINS')
            ->execute();

        // SQLite LIKE is case-insensitive for ASCII by default.
        $this->assertCount(2, $ids);
    }
}
