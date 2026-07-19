<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\Exception\UnsupportedQueryException;
use Waaseyaa\EntityStorage\Query\DefinitionValidator;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Tests\Support\V2GatewayTestBackendTrait;
use Waaseyaa\Field\FieldDefinition;

/**
 * Integration tests for {@see DefinitionValidator}.
 *
 * Tests the fail-fast contract: indexed fields are checked at boot (validateAll),
 * not at query execution time.
 *
 * Scope decision: EntityTypeInterface has no declared-queries API yet, so the
 * validator uses FieldDefinition::isIndexed() as the sole query-need signal.
 */
#[CoversClass(DefinitionValidator::class)]
#[CoversClass(UnsupportedQueryException::class)]
final class DefinitionValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the static backend slot between tests.
        TestBackendProviderRegistry::reset();
    }

    // -------------------------------------------------------------------------
    // T033-a: Indexed field + backend that supports queries → validator passes
    // -------------------------------------------------------------------------

    #[Test]
    public function indexed_field_with_supporting_backend_passes_validation(): void
    {
        // A backend that always returns true from supportsQuery.
        $registrar = $this->buildRegistrar(id: 'sql-column', supportsQuery: true);

        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            _fieldDefinitions: [
                // Explicitly routed to sql-column, which supports querying.
                'status' => new FieldDefinition(name: 'status', type: 'string')
                    ->storedIn('sql-column')
                    ->indexed(),
            ],
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $resolver = new BackendResolver($registrar);
        $validator = new DefinitionValidator($manager, $resolver);

        // No exception means validation passed.
        $validator->validateAll();
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // T033-b: Indexed field on rejecting backend → UnsupportedQueryException at boot
    // -------------------------------------------------------------------------

    #[Test]
    public function indexed_field_with_rejecting_backend_throws_at_validation_time(): void
    {
        // sql-blob always returns false from supportsQuery (FR-009).
        $registrar = $this->buildRegistrar(id: 'sql-blob', supportsQuery: false);

        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            _fieldDefinitions: [
                // Indexed field routed explicitly to sql-blob, which cannot query.
                'status' => new FieldDefinition(name: 'status', type: 'string')
                    ->storedIn('sql-blob')
                    ->indexed(),
            ],
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $resolver = new BackendResolver($registrar);
        $validator = new DefinitionValidator($manager, $resolver);

        $this->expectException(UnsupportedQueryException::class);
        $validator->validateAll();
    }

    // -------------------------------------------------------------------------
    // Structured properties on UnsupportedQueryException
    // -------------------------------------------------------------------------

    #[Test]
    public function exception_carries_backendId_fieldId_and_reason(): void
    {
        $registrar = $this->buildRegistrar(id: 'sql-blob', supportsQuery: false);

        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            _fieldDefinitions: [
                'status' => new FieldDefinition(name: 'status', type: 'string')
                    ->storedIn('sql-blob')
                    ->indexed(),
            ],
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $resolver = new BackendResolver($registrar);
        $validator = new DefinitionValidator($manager, $resolver);

        try {
            $validator->validateAll();
            $this->fail('Expected UnsupportedQueryException was not thrown.');
        } catch (UnsupportedQueryException $e) {
            $this->assertSame('sql-blob', $e->backendId);
            $this->assertSame('status', $e->fieldId);
            $this->assertNotEmpty($e->reason);
            $this->assertStringContainsString('sql-blob', $e->getMessage());
            $this->assertStringContainsString('status', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Non-indexed fields are NOT probed (no declared query need)
    // -------------------------------------------------------------------------

    #[Test]
    public function non_indexed_field_is_skipped_even_if_backend_rejects_queries(): void
    {
        $registrar = $this->buildRegistrar(id: 'sql-blob', supportsQuery: false);

        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            _fieldDefinitions: [
                // NOT indexed — no declared query need.
                'body' => new FieldDefinition(name: 'body', type: 'text')->storedIn('sql-blob'),
            ],
        );

        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $resolver = new BackendResolver($registrar);
        $validator = new DefinitionValidator($manager, $resolver);

        // Should not throw — no indexed fields → no query-support probing.
        $validator->validateAll();
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal BackendRegistrar with a single framework-owned backend.
     *
     * BackendRegistrar::build() instantiates providers via `new $fqcn()` (no args),
     * so we use TestBackendProviderRegistry to pass the backend via static slot.
     */
    private function buildRegistrar(string $id, bool $supportsQuery): BackendRegistrar
    {
        TestBackendProviderRegistry::register(new TestBackend($id, $supportsQuery));

        $fqcn = TestBackendProvider::class;
        $registrar = new BackendRegistrar([$fqcn], [$fqcn], new PermissiveFieldStorageGatewayAudit());
        $registrar->build();

        return $registrar;
    }
}

// ---------------------------------------------------------------------------
// Test-only fixture classes — defined here (not under src/) to stay autoload-dev.
// ---------------------------------------------------------------------------

/**
 * Static slot so BackendRegistrar can instantiate TestBackendProvider with no args,
 * while the test still controls which backend gets returned.
 */
final class TestBackendProviderRegistry
{
    private static ?FieldStorageBackendV2Interface $backend = null;

    public static function register(FieldStorageBackendV2Interface $backend): void
    {
        self::$backend = $backend;
    }

    public static function get(): FieldStorageBackendV2Interface
    {
        if (self::$backend === null) {
            throw new \LogicException('TestBackendProviderRegistry: no backend registered.');
        }

        return self::$backend;
    }

    public static function reset(): void
    {
        self::$backend = null;
    }
}

/**
 * Framework-owned provider that fetches its backend from the static registry.
 * Must be constructable with no arguments (BackendRegistrar::build() requirement).
 */
final class TestBackendProvider implements IsFrameworkBackendProviderV2Interface
{
    public function fieldStorageBackendsV2(): array
    {
        return [TestBackendProviderRegistry::get()];
    }
}

/**
 * Minimal backend implementation for validator tests.
 */
final class TestBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

    public function __construct(
        private readonly string $backendId,
        private readonly bool $querySupported,
    ) {}

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
        return $this->querySupported;
    }
}
