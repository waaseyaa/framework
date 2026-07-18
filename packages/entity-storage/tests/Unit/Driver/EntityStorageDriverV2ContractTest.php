<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\StorageRow;
use Waaseyaa\EntityStorage\Driver\StorageRowSet;
use Waaseyaa\EntityStorage\Driver\StorageSnapshot;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class EntityStorageDriverV2ContractTest extends TestCase
{
    #[Test]
    public function v2_uses_opaque_rows_and_snapshots_instead_of_arrays(): void
    {
        $interface = new \ReflectionClass(EntityStorageDriverV2Interface::class);

        self::assertSame('?'.StorageRow::class, (string) $interface->getMethod('read')->getReturnType());
        self::assertSame(StorageRowSet::class, (string) $interface->getMethod('readMultiple')->getReturnType());
        self::assertSame(StorageSnapshot::class, (string) $interface->getMethod('write')->getParameters()[2]->getType());
        self::assertFalse(StorageRow::class === '' || is_subclass_of(StorageRow::class, \Traversable::class));
        self::assertFalse(is_subclass_of(StorageSnapshot::class, \Stringable::class));
    }

    #[Test]
    public function unrelated_boundaries_cannot_unwrap_rows_or_snapshots(): void
    {
        $repositoryBoundary = new StorageBoundary();
        $unrelatedBoundary = new StorageBoundary();
        $driverRows = $repositoryBoundary->driverRowFactory();
        $row = $driverRows->create(['id' => '1', 'title' => 'Tansi']);
        $snapshot = $repositoryBoundary->repositorySnapshotFactory()->create(['id' => '1', 'title' => 'Tansi']);

        self::assertFalse(method_exists($driverRows, 'read'), 'A driver row factory must not carry repository hydration authority.');
        self::assertSame(['id' => '1', 'title' => 'Tansi'], $repositoryBoundary->repositoryRowReader()->read($row));

        try {
            $unrelatedBoundary->repositoryRowReader()->read($row);
            self::fail('An unrelated repository boundary must not unwrap the row.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }

        self::assertSame(['id' => '1', 'title' => 'Tansi'], $repositoryBoundary->driverSnapshotReader()->read($snapshot));
        $this->expectException(\LogicException::class);
        $unrelatedBoundary->driverSnapshotReader()->read($snapshot);
    }

    #[Test]
    public function revision_v2_contract_uses_opaque_rows_and_snapshots(): void
    {
        $interface = new \ReflectionClass(RevisionableStorageDriverV2Interface::class);

        self::assertSame('?'.StorageRow::class, (string) $interface->getMethod('readRevision')->getReturnType());
        self::assertSame(StorageRowSet::class, (string) $interface->getMethod('readMultipleRevisions')->getReturnType());
        self::assertSame('?'.StorageRow::class, (string) $interface->getMethod('readLangcodeRevision')->getReturnType());
        self::assertSame(StorageSnapshot::class, (string) $interface->getMethod('writeRevision')->getParameters()[1]->getType());
        self::assertSame(StorageSnapshot::class, (string) $interface->getMethod('updateRevision')->getParameters()[2]->getType());
        self::assertSame('int', (string) $interface->getMethod('writeRevision')->getReturnType());
    }

    #[Test]
    public function first_party_in_memory_driver_crosses_the_repository_boundary_only_as_opaque_objects(): void
    {
        $boundary = new StorageBoundary();
        $driver = new InMemoryStorageDriverV2(
            new InMemoryStorageDriver(),
            $boundary->driverRowFactory(),
            $boundary->driverSnapshotReader(),
        );
        $snapshot = $boundary->repositorySnapshotFactory()->create([
            'id' => '7',
            'title' => 'Tansi',
        ]);

        self::assertSame('7', $driver->write('node', '7', $snapshot));
        $row = $driver->read('node', '7');
        self::assertInstanceOf(StorageRow::class, $row);
        self::assertSame(
            ['id' => '7', 'title' => 'Tansi'],
            $boundary->repositoryRowReader()->read($row),
        );
    }

    #[Test]
    public function first_party_sql_driver_crosses_the_repository_boundary_only_as_opaque_objects(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'v2_sql_contract',
            label: 'V2 SQL contract',
            class: EntityInterface::class,
            keys: ['id' => 'id', 'label' => 'title'],
        );
        new SqlSchemaHandler($entityType, $database)->ensureTable();
        $boundary = new StorageBoundary();
        $driver = new SqlStorageDriverV2(
            new SqlStorageDriver(new SingleConnectionResolver($database)),
            $boundary->driverRowFactory(),
            $boundary->driverSnapshotReader(),
        );
        $snapshot = $boundary->repositorySnapshotFactory()->create([
            'id' => '7',
            'title' => 'Tansi',
        ]);

        self::assertSame('7', $driver->write('v2_sql_contract', '7', $snapshot));
        $row = $driver->read('v2_sql_contract', '7');
        self::assertInstanceOf(StorageRow::class, $row);
        self::assertSame(
            ['id' => '7', 'bundle' => '', 'title' => 'Tansi', 'langcode' => 'en'],
            $boundary->repositoryRowReader()->read($row),
        );
    }

    #[Test]
    public function every_first_party_driver_has_an_opaque_v2_boundary(): void
    {
        self::assertTrue(is_subclass_of(SqlStorageDriverV2::class, EntityStorageDriverV2Interface::class));
        self::assertTrue(is_subclass_of(InMemoryStorageDriverV2::class, EntityStorageDriverV2Interface::class));
        self::assertTrue(is_subclass_of(RevisionableStorageDriverV2::class, RevisionableStorageDriverV2Interface::class));
    }

    #[Test]
    public function repository_persistence_authority_is_private_and_non_exported(): void
    {
        $repository = new \ReflectionClass(\Waaseyaa\EntityStorage\EntityRepository::class);
        self::assertTrue($repository->getMethod('extractPersistenceValues')->isPrivate());
        self::assertTrue($repository->getProperty('persistenceValueAuthority')->isPrivate());
        self::assertFalse(method_exists(\Waaseyaa\Entity\EntityBase::class, '_storageValuesForPersistence'));
    }

    #[Test]
    public function repository_persistence_never_calls_the_public_entity_array_surface(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/EntityRepository.php');
        self::assertIsString($source);
        $tokens = token_get_all($source);
        $toArrayCalls = 0;
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $next = $tokens[$index + 1] ?? null;
            if (is_array($next) && $next[0] === T_STRING && $next[1] === 'toArray') {
                ++$toArrayCalls;
            }
        }
        self::assertSame(1, $toArrayCalls, 'Only the private diagnosed legacy third-party fallback may call toArray().');
        self::assertStringContainsString('private function extractPersistenceValues', $source);
    }
}
