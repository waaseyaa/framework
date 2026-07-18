<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Coordinator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\EntityStorageCoordinator;
use Waaseyaa\EntityStorage\Exception\UnknownBackendException;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Tests\Support\V2GatewayTestBackendTrait;
use Waaseyaa\Field\FieldDefinition;

/**
 * Integration tests for {@see EntityStorageCoordinator} field-routing fan-out.
 *
 * Covers FR-017, FR-018, FR-019, FR-020.
 *
 * Fixture entity type has three fields:
 *   - `title`   → backend-a (explicit storedIn override)
 *   - `body`    → backend-b (explicit storedIn override)
 *   - `summary` → sql-blob  (default — no override)
 *
 * Fake backends record every invocation so tests can assert routing and ordering.
 */
#[CoversClass(EntityStorageCoordinator::class)]
#[CoversClass(BackendResolver::class)]
#[CoversClass(UnknownBackendException::class)]
final class RoutingTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a BackendRegistrar with three fake backends: sql-blob (framework),
     * backend-a, and backend-b.
     *
     * @return array{BackendRegistrar, SpyBackend, SpyBackend, SpyBackend}
     */
    private function makeRegistrar(): array
    {
        $sqlBlob = new SpyBackend(ReservedBackendIds::SQL_BLOB);
        $backendA = new SpyBackend('backend-a');
        $backendB = new SpyBackend('backend-b');

        // sql-blob requires a framework provider FQCN.
        $frameworkProviderFqcn = $this->makeProviderClass([$sqlBlob], isFramework: true);
        $thirdPartyProviderFqcn = $this->makeProviderClass([$backendA, $backendB], isFramework: false);

        $registrar = new BackendRegistrar(
            [$frameworkProviderFqcn, $thirdPartyProviderFqcn],
            [$frameworkProviderFqcn],
            new PermissiveFieldStorageGatewayAudit(),
        );
        $registrar->build();

        return [$registrar, $sqlBlob, $backendA, $backendB];
    }

    /**
     * Emit an anonymous provider class on the fly.
     *
     * @param FieldStorageBackendV2Interface[] $backends
     */
    private function makeProviderClass(array $backends, bool $isFramework): string
    {
        $backendsJson = var_export($backends, true); // not usable — use closure capture

        // Unique class name per call to avoid redeclaration.
        static $counter = 0;
        $counter++;
        $suffix = $counter;

        $interfaceList = $isFramework
            ? 'HasFieldStorageBackendsV2Interface, IsFrameworkBackendProviderV2Interface'
            : 'HasFieldStorageBackendsV2Interface';

        $fqcn = 'RoutingTestProvider' . $suffix;

        // Store backends in a static registry so the anonymous eval class can pick them up.
        RoutingTestProviderRegistry::set($suffix, $backends);

        eval(<<<PHP
                use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface;
                use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;

                final class {$fqcn} implements {$interfaceList} {
                    public function fieldStorageBackendsV2(): array {
                        return \Waaseyaa\EntityStorage\Tests\Integration\Coordinator\RoutingTestProviderRegistry::get({$suffix});
                    }
                }
            PHP);

        return $fqcn;
    }

    /** Build the fixture EntityType with three fields routed to three backends. */
    private function makeEntityType(): EntityType
    {
        return new EntityType(
            id: 'routing_fixture',
            label: 'Routing Fixture',
            class: RoutingFixtureEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'title' => new FieldDefinition('title', 'string')->storedIn('backend-a'),
                'body' => new FieldDefinition('body', 'text')->storedIn('backend-b'),
                'summary' => new FieldDefinition('summary', 'string'), // default → sql-blob
            ],
        );
    }

    // ---------------------------------------------------------------------------
    // Tests — read
    // ---------------------------------------------------------------------------

    #[Test]
    public function read_dispatches_to_each_backend_for_its_field(): void
    {
        [$registrar, $sqlBlob, $backendA, $backendB] = $this->makeRegistrar();
        $entityType = $this->makeEntityType();
        $coordinator = new EntityStorageCoordinator(
            new BackendResolver($registrar),
            $registrar,
        );

        $entity = new RoutingFixtureEntity(['id' => 1, 'title' => 'T', 'body' => 'B', 'summary' => 'S']);

        $coordinator->read($entity, $entityType);

        // Each backend should have received exactly one read call for its field.
        self::assertCount(1, $backendA->readCalls, 'backend-a should read title field');
        self::assertSame('title', $backendA->readCalls[0]['field']->getName());

        self::assertCount(1, $backendB->readCalls, 'backend-b should read body field');
        self::assertSame('body', $backendB->readCalls[0]['field']->getName());

        self::assertCount(1, $sqlBlob->readCalls, 'sql-blob should read summary field');
        self::assertSame('summary', $sqlBlob->readCalls[0]['field']->getName());
    }

    // ---------------------------------------------------------------------------
    // Tests — write ordering
    // ---------------------------------------------------------------------------

    #[Test]
    public function write_invokes_primary_backend_before_alternates(): void
    {
        [$registrar, $sqlBlob, $backendA, $backendB] = $this->makeRegistrar();
        $entityType = $this->makeEntityType();
        $coordinator = new EntityStorageCoordinator(
            new BackendResolver($registrar),
            $registrar,
        );

        $entity = new RoutingFixtureEntity(['id' => 1, 'title' => 'T', 'body' => 'B', 'summary' => 'S']);

        // Record global write order across backends.
        $globalOrder = [];
        $sqlBlob->onWrite = static function (string $field) use (&$globalOrder): void {
            $globalOrder[] = 'sql-blob:' . $field;
        };
        $backendA->onWrite = static function (string $field) use (&$globalOrder): void {
            $globalOrder[] = 'backend-a:' . $field;
        };
        $backendB->onWrite = static function (string $field) use (&$globalOrder): void {
            $globalOrder[] = 'backend-b:' . $field;
        };

        $coordinator->write($entity, $entityType);

        // Primary (sql-blob) MUST appear before any alternate.
        $sqlBlobIndex = array_search('sql-blob:summary', $globalOrder, true);
        $backendAIndex = array_search('backend-a:title', $globalOrder, true);
        $backendBIndex = array_search('backend-b:body', $globalOrder, true);

        self::assertNotFalse($sqlBlobIndex, 'sql-blob write must be recorded');
        self::assertNotFalse($backendAIndex, 'backend-a write must be recorded');
        self::assertNotFalse($backendBIndex, 'backend-b write must be recorded');

        self::assertLessThan(
            min((int) $backendAIndex, (int) $backendBIndex),
            (int) $sqlBlobIndex,
            'Primary backend (sql-blob) must be written before alternates',
        );
    }

    #[Test]
    public function write_sends_each_field_to_correct_backend(): void
    {
        [$registrar, $sqlBlob, $backendA, $backendB] = $this->makeRegistrar();
        $entityType = $this->makeEntityType();
        $coordinator = new EntityStorageCoordinator(
            new BackendResolver($registrar),
            $registrar,
        );

        $entity = new RoutingFixtureEntity(['id' => 1, 'title' => 'T', 'body' => 'B', 'summary' => 'S']);
        $coordinator->write($entity, $entityType);

        self::assertCount(1, $backendA->writeCalls);
        self::assertSame('title', $backendA->writeCalls[0]['field']->getName());

        self::assertCount(1, $backendB->writeCalls);
        self::assertSame('body', $backendB->writeCalls[0]['field']->getName());

        self::assertCount(1, $sqlBlob->writeCalls);
        self::assertSame('summary', $sqlBlob->writeCalls[0]['field']->getName());
    }

    // ---------------------------------------------------------------------------
    // Tests — delete
    // ---------------------------------------------------------------------------

    #[Test]
    public function delete_calls_each_backend_once(): void
    {
        [$registrar, $sqlBlob, $backendA, $backendB] = $this->makeRegistrar();
        $entityType = $this->makeEntityType();
        $coordinator = new EntityStorageCoordinator(
            new BackendResolver($registrar),
            $registrar,
        );

        $entity = new RoutingFixtureEntity(['id' => 1]);
        $coordinator->delete($entity, $entityType);

        self::assertCount(1, $backendA->deleteCalls);
        self::assertCount(1, $backendB->deleteCalls);
        self::assertCount(1, $sqlBlob->deleteCalls);
    }

    // ---------------------------------------------------------------------------
    // Tests — error cases
    // ---------------------------------------------------------------------------

    #[Test]
    public function unknown_backend_id_throws_unknown_backend_exception(): void
    {
        [$registrar] = $this->makeRegistrar();

        $entityType = new EntityType(
            id: 'bad_fixture',
            label: 'Bad Fixture',
            class: RoutingFixtureEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'ghost' => new FieldDefinition('ghost', 'string')->storedIn('no-such-backend'),
            ],
        );

        $coordinator = new EntityStorageCoordinator(
            new BackendResolver($registrar),
            $registrar,
        );

        $entity = new RoutingFixtureEntity(['id' => 1]);

        $this->expectException(UnknownBackendException::class);
        $this->expectExceptionMessage('no-such-backend');
        $coordinator->write($entity, $entityType);
    }

    #[Test]
    public function entity_type_with_no_field_definitions_does_nothing(): void
    {
        [$registrar] = $this->makeRegistrar();

        $entityType = new EntityType(
            id: 'empty_fixture',
            label: 'Empty',
            class: RoutingFixtureEntity::class,
            keys: ['id' => 'id'],
        );

        $coordinator = new EntityStorageCoordinator(
            new BackendResolver($registrar),
            $registrar,
        );

        $entity = new RoutingFixtureEntity(['id' => 1]);

        // Must not throw.
        $coordinator->read($entity, $entityType);
        $coordinator->write($entity, $entityType);
        $coordinator->delete($entity, $entityType);

        $this->addToAssertionCount(1);
    }
}

// ---------------------------------------------------------------------------
// Test fixtures — defined at file scope to avoid eval name collisions
// ---------------------------------------------------------------------------

/**
 * Static registry so eval'd provider classes can retrieve their backend list.
 *
 * @internal Test fixture only.
 */
final class RoutingTestProviderRegistry
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
 * Spy backend that records every read/write/delete invocation.
 *
 * @internal Test fixture only.
 */
final class SpyBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

    /** @var array<int, array{entity: EntityInterface, field: FieldDefinition}> */
    public array $readCalls = [];

    /** @var array<int, array{entity: EntityInterface, field: FieldDefinition, value: mixed}> */
    public array $writeCalls = [];

    /** @var array<int, EntityInterface> */
    public array $deleteCalls = [];

    /** @var \Closure(string): void|null */
    public ?\Closure $onWrite = null;

    public function __construct(private readonly string $backendId) {}

    public function id(): string
    {
        return $this->backendId;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        $this->readCalls[] = ['entity' => $entity, 'field' => $field];

        return null;
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void
    {
        $this->writeCalls[] = ['entity' => $entity, 'field' => $field, 'value' => $value];

        if ($this->onWrite !== null) {
            ($this->onWrite)($field->getName());
        }
    }

    public function delete(EntityInterface $entity): void
    {
        $this->deleteCalls[] = $entity;
    }

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}

/**
 * Minimal content entity for routing tests.
 *
 * @internal Test fixture only.
 */
final class RoutingFixtureEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'routing_fixture', ['id' => 'id']);
    }
}
