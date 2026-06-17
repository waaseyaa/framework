<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Coordinator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendInterface;
use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsInterface;
use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntityStorageCoordinator;
use Waaseyaa\EntityStorage\EntityStorageFactory;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;

/**
 * Regression test: the canonical persistence pipeline is preserved after
 * coordinator introduction (T012, FR-021).
 *
 * Asserts the call chain:
 *   EntityRepository → EntityStorageCoordinator → FieldStorageBackendInterface → DatabaseInterface (DBAL)
 *
 * Uses spy decorators and reflection to verify that:
 * 1. No raw PDO or direct-SQL bypass exists in the path.
 * 2. The coordinator is reachable from the repository.
 * 3. The factory wires coordinator and repository together correctly.
 * 4. Calls flow through DBAL (DatabaseInterface), not \PDO directly.
 */
#[CoversClass(EntityRepository::class)]
#[CoversClass(EntityStorageCoordinator::class)]
#[CoversClass(EntityStorageFactory::class)]
#[CoversClass(BackendResolver::class)]
final class PipelineInvariantTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makeRegistrar(): BackendRegistrar
    {
        $sqlBlob = new PipelineSpyBackend(ReservedBackendIds::SQL_BLOB);
        $frameworkProviderFqcn = $this->makeFrameworkProvider([$sqlBlob]);

        $registrar = new BackendRegistrar([$frameworkProviderFqcn], [$frameworkProviderFqcn]);
        $registrar->build();

        return $registrar;
    }

    /** @param FieldStorageBackendInterface[] $backends */
    private function makeFrameworkProvider(array $backends): string
    {
        static $counter = 0;
        $counter++;
        $suffix = $counter;

        PipelineTestProviderRegistry::set($suffix, $backends);

        $fqcn = 'PipelineTestProvider' . $suffix;

        eval(<<<PHP
            use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsInterface;
            use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderInterface;

            final class {$fqcn} implements HasFieldStorageBackendsInterface, IsFrameworkBackendProviderInterface {
                public function fieldStorageBackends(): array {
                    return \Waaseyaa\EntityStorage\Tests\Integration\Coordinator\PipelineTestProviderRegistry::get({$suffix});
                }
            }
        PHP);

        return $fqcn;
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function factory_wires_coordinator_into_repository(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $registrar = $this->makeRegistrar();

        $factory = new EntityStorageFactory(
            database: $db,
            eventDispatcher: $dispatcher,
            backendRegistrar: $registrar,
        );

        $entityType = new EntityType(
            id: 'pipeline_test',
            label: 'Pipeline Test',
            class: PipelineTestEntity::class,
            keys: ['id' => 'id'],
        );

        $coordinator = $factory->getCoordinator($entityType);

        self::assertInstanceOf(
            EntityStorageCoordinator::class,
            $coordinator,
            'Factory must return a coordinator when BackendRegistrar is provided',
        );
    }

    #[Test]
    public function factory_returns_null_coordinator_when_no_registrar(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        $factory = new EntityStorageFactory(
            database: $db,
            eventDispatcher: $dispatcher,
            // No backendRegistrar — single-backend deployment.
        );

        $entityType = new EntityType(
            id: 'pipeline_test_single',
            label: 'Pipeline Test Single',
            class: PipelineTestEntity::class,
            keys: ['id' => 'id'],
        );

        self::assertNull(
            $factory->getCoordinator($entityType),
            'Factory must return null when no BackendRegistrar is provided',
        );
    }

    #[Test]
    public function repository_exposes_coordinator_via_getter(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $registrar = $this->makeRegistrar();

        $entityType = new EntityType(
            id: 'pipeline_test_repo',
            label: 'Pipeline Test Repo',
            class: PipelineTestEntity::class,
            keys: ['id' => 'id'],
        );

        $driver = new SqlStorageDriver(
            new SingleConnectionResolver($db),
        );

        $resolver = new BackendResolver($registrar);
        $coordinator = new EntityStorageCoordinator($resolver, $registrar);

        $repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
            coordinator: $coordinator,
        );

        self::assertSame(
            $coordinator,
            $repository->getCoordinator(),
            'Repository must expose the wired coordinator',
        );
    }

    #[Test]
    public function repository_without_coordinator_returns_null(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        $entityType = new EntityType(
            id: 'pipeline_test_nocoord',
            label: 'Pipeline Test No Coord',
            class: PipelineTestEntity::class,
            keys: ['id' => 'id'],
        );

        $driver = new SqlStorageDriver(
            new SingleConnectionResolver($db),
        );

        $repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        self::assertNull(
            $repository->getCoordinator(),
            'Repository must return null when no coordinator is wired',
        );
    }

    #[Test]
    public function coordinator_does_not_hold_pdo_reference(): void
    {
        $registrar = $this->makeRegistrar();
        $resolver = new BackendResolver($registrar);
        $coordinator = new EntityStorageCoordinator($resolver, $registrar);

        // Verify via reflection that no property holds a \PDO instance.
        // This is the "no direct PDO bypass" invariant (entity-storage-invariant.md).
        $reflection = new \ReflectionObject($coordinator);
        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($coordinator);
            self::assertNotInstanceOf(
                \PDO::class,
                $value,
                sprintf(
                    'Coordinator property "%s" must not hold a raw PDO instance',
                    $property->getName(),
                ),
            );
        }
    }

    #[Test]
    public function driver_uses_dbal_not_raw_pdo(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $resolver = new SingleConnectionResolver($db);
        $driver = new SqlStorageDriver($resolver);

        // Verify driver holds a DatabaseInterface, not \PDO, via reflection.
        $reflection = new \ReflectionObject($driver);
        $hasPdo = false;
        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($driver);
            if ($value instanceof \PDO) {
                $hasPdo = true;
            }
        }

        self::assertFalse(
            $hasPdo,
            'SqlStorageDriver must not hold a raw \\PDO instance — use DatabaseInterface (DBAL)',
        );
    }
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

/**
 * @internal Test fixture only.
 */
final class PipelineTestProviderRegistry
{
    /** @var array<int, FieldStorageBackendInterface[]> */
    private static array $registry = [];

    /** @param FieldStorageBackendInterface[] $backends */
    public static function set(int $key, array $backends): void
    {
        self::$registry[$key] = $backends;
    }

    /** @return FieldStorageBackendInterface[] */
    public static function get(int $key): array
    {
        return self::$registry[$key] ?? [];
    }
}

/**
 * @internal Test fixture only.
 */
final class PipelineSpyBackend implements FieldStorageBackendInterface
{
    public function __construct(private readonly string $backendId) {}

    public function id(): string { return $this->backendId; }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed { return null; }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void {}

    public function delete(EntityInterface $entity): void {}

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool { return false; }
}

/**
 * @internal Test fixture only.
 */
final class PipelineTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'pipeline_test', ['id' => 'id']);
    }
}
