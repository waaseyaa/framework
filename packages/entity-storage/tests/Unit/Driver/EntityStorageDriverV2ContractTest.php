<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\LegacyStorageDriverAdapter;
use Waaseyaa\EntityStorage\Driver\StorageRow;
use Waaseyaa\EntityStorage\Driver\StorageRowSet;
use Waaseyaa\EntityStorage\Driver\StorageSnapshot;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\Entity\EntityInterface;

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
    public function legacy_adapter_preserves_v1_read_behavior_inside_the_new_shape(): void
    {
        $boundary = new StorageBoundary();
        $legacy = new class implements EntityStorageDriverInterface {
            public function read(string $entityType, string $id, ?string $langcode = null): ?array { return ['id' => $id, 'title' => 'Hello']; }
            public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array { return ['1' => ['id' => '1']]; }
            public function write(string $entityType, string $id, array $values): string { return $id; }
            public function remove(string $entityType, string $id): void {}
            public function exists(string $entityType, string $id): bool { return true; }
            public function count(string $entityType, array $criteria = []): int { return 1; }
            public function findBy(string $entityType, array $criteria = [], ?array $orderBy = null, ?int $limit = null): array { return [['id' => '1']]; }
            public function findTranslations(string $entityType, string $id, ?string $defaultLangcode = null): array { return ['en' => ['id' => $id, 'langcode' => 'en']]; }
        };

        $events = [];
        $adapter = new LegacyStorageDriverAdapter(
            $legacy,
            $boundary->driverRowFactory(),
            $boundary->driverSnapshotReader(),
            static function (string $channel, array $context) use (&$events): void {
                $events[] = [$channel, $context];
            },
        );

        self::assertSame('entity.deprecation', $events[0][0]);
        self::assertSame('v1_storage_driver_adapter', $events[0][1]['event']);
        self::assertInstanceOf(StorageRow::class, $adapter->read('node', '1'));
        self::assertCount(1, $adapter->readMultiple('node', ['1']));
        self::assertCount(1, $adapter->findBy('node'));
        self::assertCount(1, $adapter->findTranslations('node', '1'));
        self::assertSame('1', $adapter->write('node', '1', $boundary->repositorySnapshotFactory()->create(['id' => '1'])));
        $this->expectException(\LogicException::class);
        serialize($adapter->read('node', '1'));
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
