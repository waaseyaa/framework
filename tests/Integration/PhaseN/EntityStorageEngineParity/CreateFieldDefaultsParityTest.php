<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityStorageEngineParity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;

/**
 * C-22 WP3 — behavior-identity harness: `create()` field-default parity.
 *
 * `EntityRepository::create()` and `SqlEntityStorage::create()` are two
 * independent entry points that both delegate to the SAME shared
 * {@see \Waaseyaa\EntityStorage\Hydration\EntityInstantiator} for field-default
 * application and hydration (rather than each carrying its own copy of the
 * defaulting logic) precisely so this parity holds by construction. This test
 * pins the contract: given the same `$values`, both engines produce entities
 * with identical values — defaults filled for omitted fields, explicit values
 * left untouched — and both mark the entity `isNew()`.
 *
 * This suite must stay green through C-22 WP4 (see docs/notes/c22-consumer-inventory.md).
 */
#[CoversNothing]
final class CreateFieldDefaultsParityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private SqlEntityStorage $storage;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        $this->entityType = new EntityType(
            id: 'parity_create_entity',
            label: 'Parity Create Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'title', 'langcode' => 'langcode'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                // No value supplied at create() time — both engines must fill
                // this from the field definition's default.
                'status' => new FieldDefinition(name: 'status', type: 'string', defaultValue: 'draft'),
                'priority' => new FieldDefinition(name: 'priority', type: 'integer', defaultValue: 0),
            ],
        );

        new SqlSchemaHandler($this->entityType, $this->database)->ensureTable();

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($this->entityType, $this->database, $dispatcher);

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $this->repository = new EntityRepository($this->entityType, $driver, $dispatcher, database: $this->database);
    }

    #[Test]
    public function bothEnginesFillTheSameDefaultsForOmittedFields(): void
    {
        $storageEntity = $this->storage->create(['title' => 'From Storage']);
        $repositoryEntity = $this->repository->create(['title' => 'From Repository']);

        $this->assertSame('draft', $storageEntity->get('status'));
        $this->assertSame('draft', $repositoryEntity->get('status'));
        $this->assertSame(0, $storageEntity->get('priority'));
        $this->assertSame(0, $repositoryEntity->get('priority'));
    }

    #[Test]
    public function bothEnginesLeaveExplicitValuesUntouched(): void
    {
        $values = ['title' => 'Explicit', 'status' => 'published', 'priority' => 5];

        $storageEntity = $this->storage->create($values);
        $repositoryEntity = $this->repository->create($values);

        $this->assertSame('published', $storageEntity->get('status'));
        $this->assertSame('published', $repositoryEntity->get('status'));
        $this->assertSame(5, $storageEntity->get('priority'));
        $this->assertSame(5, $repositoryEntity->get('priority'));
    }

    #[Test]
    public function bothEnginesMarkTheEntityNew(): void
    {
        $storageEntity = $this->storage->create(['title' => 'New via storage']);
        $repositoryEntity = $this->repository->create(['title' => 'New via repository']);

        $this->assertTrue($storageEntity->isNew());
        $this->assertTrue($repositoryEntity->isNew());
    }
}
