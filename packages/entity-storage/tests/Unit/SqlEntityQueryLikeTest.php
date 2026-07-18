<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryLikeTest extends TestCase
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
    public function containsOperatorMatchesSubstring(): void
    {
        $this->repository->save($this->repository->create(['title' => 'Hello World']), validate: false);
        $this->repository->save($this->repository->create(['title' => 'Goodbye Moon']), validate: false);
        $this->repository->save($this->repository->create(['title' => 'World Peace']), validate: false);

        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->condition('title', 'World', 'CONTAINS')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function startsWithOperatorMatchesPrefix(): void
    {
        $this->repository->save($this->repository->create(['title' => 'Hello World']), validate: false);
        $this->repository->save($this->repository->create(['title' => 'Goodbye Moon']), validate: false);
        $this->repository->save($this->repository->create(['title' => 'Hello Again']), validate: false);

        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->condition('title', 'Hello', 'STARTS_WITH')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function containsOperatorEscapesPercentWildcard(): void
    {
        $this->repository->save($this->repository->create(['title' => '100% Complete']), validate: false);
        $this->repository->save($this->repository->create(['title' => '100 items found']), validate: false);

        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->condition('title', '100%', 'CONTAINS')
            ->execute();

        // Only the entity with a literal "100%" should match, not "100 items found".
        $this->assertCount(1, $ids);
    }

    #[Test]
    public function containsOperatorIsCaseInsensitive(): void
    {
        $this->repository->save($this->repository->create(['title' => 'Hello World']), validate: false);
        $this->repository->save($this->repository->create(['title' => 'hello world']), validate: false);

        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->condition('title', 'hello', 'CONTAINS')
            ->execute();

        // SQLite LIKE is case-insensitive for ASCII by default.
        $this->assertCount(2, $ids);
    }
}
