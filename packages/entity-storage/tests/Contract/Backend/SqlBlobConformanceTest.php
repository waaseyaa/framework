<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Contract\Backend;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Backend\SqlBlobBackend;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\Contract\FieldStorageBackendContractTestCase;
use Waaseyaa\Field\FieldDefinition;

/**
 * Conformance suite for SqlBlobBackend (T064, FR-049, FR-050).
 *
 * Extends the shared {@see FieldStorageBackendContractTestCase} harness and
 * verifies that `sql-blob` passes every contract test. Uses an in-memory
 * SQLite database; no filesystem writes.
 *
 * supportsQuery() is expected to return false for all field types — the blob
 * backend delegates queries to SqlEntityStorage's column-equality path.
 */
#[CoversNothing]
final class SqlBlobConformanceTest extends FieldStorageBackendContractTestCase
{
    /** @internal */
    public const ENTITY_TYPE_ID = 'conformance_blob_entity';

    private const TABLE = 'conformance_blob_entity';
    private const ID_KEY = 'id';

    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite(':memory:');
        $this->seedRow();
        // Call parent last so createBackend()/prepareFixtureEntity() see a seeded DB.
        parent::setUp();
    }

    // -------------------------------------------------------------------------
    // FieldStorageBackendContractTestCase — template method implementations
    // -------------------------------------------------------------------------

    protected function createBackend(): FieldStorageBackendInterface
    {
        return new SqlBlobBackend(
            database: $this->db,
            entityTableName: self::TABLE,
            idKey: self::ID_KEY,
            entityTypeId: self::ENTITY_TYPE_ID,
        );
    }

    protected function prepareFixtureEntity(): EntityInterface
    {
        return new ConformanceBlobEntity(['id' => 1]);
    }

    protected function fixtureField(): FieldDefinition
    {
        // A non-column field — will be stored in the _data blob.
        return new FieldDefinition(name: 'description', type: 'string');
    }

    protected function fixtureValue(): mixed
    {
        return 'conformance-fixture-value';
    }

    protected function alternateValue(): mixed
    {
        return 'conformance-alternate-value';
    }

    protected function supportsQueryField(): FieldDefinition
    {
        return new FieldDefinition(name: 'description', type: 'string');
    }

    protected function expectSupportsQuery(): bool
    {
        // sql-blob always returns false from supportsQuery() (FR-009, FR-010).
        return false;
    }

    // -------------------------------------------------------------------------
    // Additional blob-specific check: id() constant
    // -------------------------------------------------------------------------

    public function testIdMatchesReservedConstant(): void
    {
        self::assertSame(ReservedBackendIds::SQL_BLOB, $this->createBackend()->id());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Bootstrap the entity table and seed one row so read()/write() have a
     * target row to UPDATE.
     */
    private function seedRow(): void
    {
        $entityType = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Conformance Blob Entity',
            class: ConformanceBlobEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label', 'bundle' => 'bundle', 'langcode' => 'langcode'],
        );

        $schemaHandler = new SqlSchemaHandler(
            entityType: $entityType,
            database: $this->db,
        );
        $schemaHandler->ensureTable();

        $repository = new EntityRepository(
            entityType: $entityType,
            driver: new SqlStorageDriver(new SingleConnectionResolver($this->db)),
            eventDispatcher: new EventDispatcher(),
        );

        $entity = $repository->create([
            'uuid'     => 'cb000000-0000-0000-0000-000000000001',
            'label'    => 'Conformance Fixture',
            'bundle'   => 'default',
            'langcode' => 'en',
        ]);
        $repository->save($entity, validate: false);
    }
}

/**
 * Minimal entity fixture for SqlBlobConformanceTest.
 *
 * Uses #[ContentEntityType] and #[ContentEntityKeys] attributes so the
 * parent ContentEntityBase::fromStorage() path resolves type id and keys
 * from metadata — avoiding a narrow constructor override that breaks named
 * parameter hydration (CLAUDE.md gotcha: "Entity subclass constructors").
 *
 * Defined here (not under src/) so it remains autoload-dev only and does not
 * trigger the production boot gotcha described in CLAUDE.md.
 */
#[ContentEntityType(id: SqlBlobConformanceTest::ENTITY_TYPE_ID)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
final class ConformanceBlobEntity extends ContentEntityBase
{
}
