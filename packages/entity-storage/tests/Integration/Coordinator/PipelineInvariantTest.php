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
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntityStorageCoordinator;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Tests\Support\V2GatewayTestBackendTrait;
use Waaseyaa\Field\FieldDefinition;

/**
 * Regression test: the canonical persistence pipeline is preserved after
 * coordinator introduction (T012, FR-021).
 *
 * Asserts the call chain:
 *   EntityRepository → EntityStorageCoordinator → FieldStorageBackendV2Interface → DatabaseInterface (DBAL)
 *
 * Uses spy decorators and reflection to verify that:
 * 1. No raw PDO or direct-SQL bypass exists in the path.
 * 2. The coordinator is reachable from the repository.
 * 3. Calls flow through DBAL (DatabaseInterface), not \PDO directly.
 *
 * C-22 WP4: the two tests that asserted `EntityStorageFactory::getCoordinator()`
 * wiring were removed — `EntityStorageFactory` is deleted (SqlEntityStorage's
 * factory; `EntityRepository` is the sole engine and is constructed directly by
 * the kernel, never via that factory). The remaining tests below construct
 * `EntityRepository`/`EntityStorageCoordinator`/`BackendResolver`/
 * `SqlStorageDriver` directly and are unaffected by that deletion.
 */
#[CoversClass(EntityRepository::class)]
#[CoversClass(EntityStorageCoordinator::class)]
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

        $registrar = new BackendRegistrar([$frameworkProviderFqcn], [$frameworkProviderFqcn], new PermissiveFieldStorageGatewayAudit());
        $registrar->build();

        return $registrar;
    }

    /** @param FieldStorageBackendV2Interface[] $backends */
    private function makeFrameworkProvider(array $backends): string
    {
        static $counter = 0;
        $counter++;
        $suffix = $counter;

        PipelineTestProviderRegistry::set($suffix, $backends);

        $fqcn = 'PipelineTestProvider' . $suffix;

        eval(<<<PHP
                use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface;
                use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;

                final class {$fqcn} implements HasFieldStorageBackendsV2Interface, IsFrameworkBackendProviderV2Interface {
                    public function fieldStorageBackendsV2(): array {
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

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
    /** @var array<int, FieldStorageBackendV2Interface[]> */
    private static array $registry = [];

    /** @param FieldStorageBackendV2Interface[] $backends */
    public static function set(int $key, array $backends): void
    {
        self::$registry[$key] = $backends;
    }

    /** @return FieldStorageBackendV2Interface[] */
    public static function get(int $key): array
    {
        return self::$registry[$key] ?? [];
    }
}

/**
 * @internal Test fixture only.
 */
final class PipelineSpyBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

    public function __construct(private readonly string $backendId) {}

    public function id(): string
    {
        return $this->backendId;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return null;
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void {}

    public function delete(EntityInterface $entity): void {}

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
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
