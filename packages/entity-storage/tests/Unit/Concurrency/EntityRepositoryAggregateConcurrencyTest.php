<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

final class EntityRepositoryAggregateConcurrencyTest extends TestCase
{
    private DBALDatabase $database;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $type = new EntityType(
            id: 'aggregate',
            label: 'Aggregate',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        (new SqlSchemaHandler($type, $this->database))->ensureTable();
        $this->database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_entity_mutation_authority (
                storage_authority VARCHAR(191) NOT NULL,
                tenant_id VARCHAR(191) NOT NULL,
                entity_type VARCHAR(191) NOT NULL,
                entity_id VARCHAR(191) NOT NULL,
                aggregate_version INTEGER NOT NULL,
                mutation_tag VARCHAR(64) NOT NULL,
                lifecycle_state VARCHAR(16) NOT NULL,
                PRIMARY KEY (storage_authority, tenant_id, entity_type, entity_id)
            )
            SQL);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $this->repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($this->database)),
            $dispatcher,
            database: $this->database,
        );
    }

    #[Test]
    public function nonRevisionableTwoReaderRaceHasOneWinnerWithoutOptionalContext(): void
    {
        $this->seed('1', 'v1');
        $winner = $this->repository->find('1');
        $loser = $this->repository->find('1');
        self::assertInstanceOf(TestStorageEntity::class, $winner);
        self::assertInstanceOf(TestStorageEntity::class, $loser);
        self::assertNotNull($winner->mutationToken());
        self::assertSame($winner->mutationToken()?->toOpaqueString(), $loser->mutationToken()?->toOpaqueString());

        $winner->set('label', 'winner');
        $this->repository->save($winner);
        $loser->set('label', 'loser');

        try {
            $this->repository->save($loser);
            self::fail('Stale writer was accepted.');
        } catch (EntityMutationConflictException $exception) {
            self::assertNull($exception->currentToken);
        }
        self::assertSame('winner', $this->repository->find('1')?->label());
    }

    #[Test]
    public function staleDeleteCannotEraseANewerUpdate(): void
    {
        $this->seed('1', 'v1');
        $staleDelete = $this->repository->find('1');
        $winner = $this->repository->find('1');
        self::assertNotNull($staleDelete);
        self::assertNotNull($winner);

        $winner->set('label', 'winner');
        $this->repository->save($winner);

        $this->expectException(EntityMutationConflictException::class);
        try {
            $this->repository->delete($staleDelete);
        } finally {
            self::assertSame('winner', $this->repository->find('1')?->label());
        }
    }

    #[Test]
    public function oneStaleItemRollsBackTheWholeBatch(): void
    {
        $this->seed('1', 'one');
        $this->seed('2', 'two');
        $batchOne = $this->repository->find('1');
        $batchTwo = $this->repository->find('2');
        $winner = $this->repository->find('2');
        self::assertNotNull($batchOne);
        self::assertNotNull($batchTwo);
        self::assertNotNull($winner);

        $winner->set('label', 'winner-two');
        $this->repository->save($winner);
        $batchOne->set('label', 'batch-one');
        $batchTwo->set('label', 'batch-two');

        try {
            $this->repository->saveMany([$batchOne, $batchTwo]);
            self::fail('Partially stale batch was accepted.');
        } catch (EntityMutationConflictException) {
            self::assertSame('one', $this->repository->find('1')?->label());
            self::assertSame('winner-two', $this->repository->find('2')?->label());
        }
    }

    private function seed(string $id, string $label): void
    {
        $entity = new TestStorageEntity(['id' => $id, 'uuid' => "uuid-{$id}", 'label' => $label]);
        $entity->enforceIsNew();
        $this->repository->save($entity);
        self::assertNotNull($entity->mutationToken());
    }
}
