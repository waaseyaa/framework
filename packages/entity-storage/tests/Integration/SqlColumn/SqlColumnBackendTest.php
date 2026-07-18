<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\SqlColumn;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Backend\SqlColumnBackend;
use Waaseyaa\EntityStorage\Backend\SqlColumnSchemaBuilder;
use Waaseyaa\EntityStorage\Backend\TypeMapping;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\Field\FieldDefinition;

/**
 * Integration tests for the sql-column backend (T030).
 *
 * Uses an in-memory SQLite database via DBALDatabase::createSqlite().
 * Covers:
 * - Table creation with mixed-type field columns
 * - B-tree index emission for indexed() fields (verified via EXPLAIN QUERY PLAN)
 * - Decimal lossless round-trip (TEXT in SQLite)
 * - Datetime ISO-8601 storage and retrieval
 * - float_vector_<n> rejection at schema-build time
 * - §8.2 type mapping coverage for SQLite and Postgres
 * - supportsQuery() semantics
 */
#[CoversNothing]
final class SqlColumnBackendTest extends TestCase
{
    private DBALDatabase $db;
    private EntityType $entityType;
    private string $tableName = 'test_entity';
    private string $idKey = 'id';

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        $this->entityType = new EntityType(
            id: $this->tableName,
            label: 'Test Entity',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle', 'langcode' => 'langcode'],
        );
    }

    // -------------------------------------------------------------------------
    // Schema builder: mixed-type table creation (T026)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_table_with_per_field_columns(): void
    {
        $fields = $this->makeMixedFields();
        $builder = new SqlColumnSchemaBuilder($this->db);
        $builder->buildTable($this->entityType, $this->tableName, $fields, $this->makeBaseSpec());

        $schema = $this->db->schema();
        self::assertTrue($schema->tableExists($this->tableName));
        self::assertTrue($schema->fieldExists($this->tableName, 'title_field'));
        self::assertTrue($schema->fieldExists($this->tableName, 'count'));
        self::assertTrue($schema->fieldExists($this->tableName, 'active'));
        self::assertTrue($schema->fieldExists($this->tableName, 'created_at'));
        self::assertTrue($schema->fieldExists($this->tableName, 'metadata'));
    }

    #[Test]
    public function it_rejects_float_vector_field_at_schema_build_time(): void
    {
        $fields = [
            new FieldDefinition(name: 'embedding', type: 'float_vector_768'),
        ];
        $builder = new SqlColumnSchemaBuilder($this->db);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('float_vector_768');
        $this->expectExceptionMessage("storedIn('vector')");

        $builder->buildTable($this->entityType, $this->tableName, $fields, $this->makeBaseSpec());
    }

    #[Test]
    public function it_skips_float_vector_field_routed_to_different_backend(): void
    {
        // A float_vector field explicitly routed away via storedIn('vector') must NOT be rejected.
        $fields = [
            new FieldDefinition(name: 'embedding', type: 'float_vector_768')->storedIn('vector'),
            new FieldDefinition(name: 'name', type: 'string'),
        ];
        $builder = new SqlColumnSchemaBuilder($this->db);
        $builder->buildTable($this->entityType, $this->tableName, $fields, $this->makeBaseSpec());

        $schema = $this->db->schema();
        self::assertTrue($schema->fieldExists($this->tableName, 'name'));
        self::assertFalse($schema->fieldExists($this->tableName, 'embedding'));
    }

    // -------------------------------------------------------------------------
    // Indexed field: B-tree index emitted (T027)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_emits_index_for_indexed_fields(): void
    {
        $fields = [
            new FieldDefinition(name: 'slug', type: 'string')->indexed(),
            new FieldDefinition(name: 'score', type: 'int'),
        ];
        $builder = new SqlColumnSchemaBuilder($this->db);
        $builder->buildTable($this->entityType, $this->tableName, $fields, $this->makeBaseSpec());

        // Verify the index was created by querying sqlite_master.
        // EXPLAIN QUERY PLAN is unreliable without statistics on an empty table.
        $expectedIndex = $this->tableName . '_slug_idx';
        $found = false;
        foreach ($this->db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name=?",
            [$expectedIndex],
        ) as $row) {
            $row = (array) $row;
            if ($row['name'] === $expectedIndex) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Expected index ' . $expectedIndex . ' to exist in sqlite_master');
    }

    // -------------------------------------------------------------------------
    // CRUD round-trip: mixed types (T028)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_round_trips_mixed_field_types(): void
    {
        $this->createMixedTable();
        $backend = $this->makeBackend();
        $entity = new TestEntity(['id' => 1]);

        $backend->write($entity, new FieldDefinition(name: 'title_field', type: 'string'), 'Hello World');
        $backend->write($entity, new FieldDefinition(name: 'count', type: 'int'), 42);
        $backend->write($entity, new FieldDefinition(name: 'active', type: 'bool'), true);
        $backend->write($entity, new FieldDefinition(name: 'created_at', type: 'datetime'), '2026-05-11T12:00:00Z');
        $backend->write($entity, new FieldDefinition(name: 'metadata', type: 'json'), ['key' => 'value', 'n' => 7]);

        $title = $backend->read($entity, new FieldDefinition(name: 'title_field', type: 'string'));
        $count = $backend->read($entity, new FieldDefinition(name: 'count', type: 'int'));
        $active = $backend->read($entity, new FieldDefinition(name: 'active', type: 'bool'));
        $created = $backend->read($entity, new FieldDefinition(name: 'created_at', type: 'datetime'));
        $meta = $backend->read($entity, new FieldDefinition(name: 'metadata', type: 'json'));

        self::assertSame('Hello World', $title);
        self::assertSame(42, (int) $count);
        self::assertTrue((bool) $active);
        self::assertSame('2026-05-11T12:00:00Z', $created);
        self::assertIsArray($meta);
        self::assertSame('value', $meta['key']);
        self::assertSame(7, $meta['n']);
    }

    // -------------------------------------------------------------------------
    // Decimal lossless round-trip (T030 specific)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_stores_decimal_as_lossless_text_in_sqlite(): void
    {
        $this->createTableWithField('price', 'decimal');
        $backend = $this->makeBackend();
        $entity = new TestEntity(['id' => 1]);

        // A value that would lose precision as IEEE-754 float.
        $decimalValue = '123456789.999999999';
        $backend->write($entity, new FieldDefinition(name: 'price', type: 'decimal'), $decimalValue);
        $result = $backend->read($entity, new FieldDefinition(name: 'price', type: 'decimal'));

        self::assertSame($decimalValue, $result, 'Decimal must round-trip losslessly as TEXT in SQLite');
    }

    // -------------------------------------------------------------------------
    // Datetime ISO-8601 storage + retrieval
    // -------------------------------------------------------------------------

    #[Test]
    public function it_stores_and_retrieves_datetime_as_iso8601(): void
    {
        $this->createTableWithField('published_at', 'datetime');
        $backend = $this->makeBackend();
        $entity = new TestEntity(['id' => 1]);

        $iso = '2026-05-11T09:30:00+00:00';
        $backend->write($entity, new FieldDefinition(name: 'published_at', type: 'datetime'), $iso);
        $result = $backend->read($entity, new FieldDefinition(name: 'published_at', type: 'datetime'));

        self::assertSame($iso, $result);
    }

    // -------------------------------------------------------------------------
    // supportsQuery
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_supports_query_true_for_standard_field_types(): void
    {
        $backend = $this->makeBackend();
        $query = new class implements EntityQuery {};

        foreach (['string', 'int', 'bool', 'datetime', 'json', 'uuid', 'text', 'float', 'decimal'] as $type) {
            $field = new FieldDefinition(name: 'f', type: $type);
            self::assertTrue(
                $backend->supportsQuery($field, $query),
                "supportsQuery should return true for field type: {$type}",
            );
        }
    }

    #[Test]
    public function it_returns_supports_query_false_for_float_vector(): void
    {
        $backend = $this->makeBackend();
        $query = new class implements EntityQuery {};
        $field = new FieldDefinition(name: 'embedding', type: 'float_vector_768');

        self::assertFalse($backend->supportsQuery($field, $query));
    }

    // -------------------------------------------------------------------------
    // TypeMapping: §8.2 coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function type_mapping_covers_all_sqlite_types(): void
    {
        $platform = TypeMapping::PLATFORM_SQLITE;

        $cases = [
            'string'   => 'TEXT',
            'text'     => 'TEXT',
            'int'      => 'INTEGER',
            'bigint'   => 'INTEGER',
            'bool'     => 'INTEGER',
            'datetime' => 'TEXT',
            'json'     => 'TEXT',
            'uuid'     => 'TEXT',
            'float'    => 'REAL',
            'decimal'  => 'TEXT',
        ];

        foreach ($cases as $fieldType => $expected) {
            $actual = TypeMapping::columnTypeFor($platform, $fieldType);
            self::assertSame($expected, $actual, "SQLite mapping for {$fieldType}");
        }
    }

    #[Test]
    public function type_mapping_covers_postgres_types(): void
    {
        $platform = TypeMapping::PLATFORM_POSTGRESQL;

        $cases = [
            'string'   => 'TEXT',
            'text'     => 'TEXT',
            'int'      => 'INTEGER',
            'bigint'   => 'BIGINT',
            'bool'     => 'BOOLEAN',
            'datetime' => 'TIMESTAMPTZ',
            'json'     => 'JSONB',
            'uuid'     => 'UUID',
            'float'    => 'DOUBLE PRECISION',
            'decimal'  => 'NUMERIC',
        ];

        foreach ($cases as $fieldType => $expected) {
            $actual = TypeMapping::columnTypeFor($platform, $fieldType);
            self::assertSame($expected, $actual, "Postgres mapping for {$fieldType}");
        }
    }

    #[Test]
    public function type_mapping_postgres_string_uses_varchar_when_length_given(): void
    {
        $actual = TypeMapping::columnTypeFor(TypeMapping::PLATFORM_POSTGRESQL, 'string', length: 128);
        self::assertSame('VARCHAR(128)', $actual);
    }

    #[Test]
    public function type_mapping_postgres_decimal_with_precision_and_scale(): void
    {
        $actual = TypeMapping::columnTypeFor(TypeMapping::PLATFORM_POSTGRESQL, 'decimal', precision: 10, scale: 4);
        self::assertSame('NUMERIC(10,4)', $actual);
    }

    #[Test]
    public function type_mapping_rejects_float_vector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("storedIn('vector')");
        TypeMapping::columnTypeFor(TypeMapping::PLATFORM_SQLITE, 'float_vector_768');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return FieldDefinition[] */
    private function makeMixedFields(): array
    {
        return [
            new FieldDefinition(name: 'title_field', type: 'string'),
            new FieldDefinition(name: 'count', type: 'int'),
            new FieldDefinition(name: 'active', type: 'bool'),
            new FieldDefinition(name: 'created_at', type: 'datetime'),
            new FieldDefinition(name: 'metadata', type: 'json'),
        ];
    }

    private function createMixedTable(): void
    {
        $builder = new SqlColumnSchemaBuilder($this->db);
        $builder->buildTable(
            $this->entityType,
            $this->tableName,
            $this->makeMixedFields(),
            $this->makeBaseSpec(),
        );
        $this->insertBaseRow();
    }

    private function createTableWithField(string $fieldName, string $fieldType): void
    {
        $builder = new SqlColumnSchemaBuilder($this->db);
        $builder->buildTable(
            $this->entityType,
            $this->tableName,
            [new FieldDefinition(name: $fieldName, type: $fieldType)],
            $this->makeBaseSpec(),
        );
        $this->insertBaseRow();
    }

    private function insertBaseRow(): void
    {
        $this->db->insert($this->tableName)
            ->fields(['id', 'uuid', 'bundle', 'title', 'langcode'])
            ->values([
                'id'       => 1,
                'uuid'     => 'test-uuid-1',
                'bundle'   => 'default',
                'title'    => '',
                'langcode' => 'en',
            ])
            ->execute();
    }

    private function makeBackend(): FieldStorageBackendGateway
    {
        SqlColumnTestBackendProvider::$backend = new SqlColumnBackend(
            database: $this->db,
            entityTableName: $this->tableName,
            idKey: $this->idKey,
            entityTypeId: $this->tableName,
        );
        $registrar = new BackendRegistrar(
            [SqlColumnTestBackendProvider::class],
            [SqlColumnTestBackendProvider::class],
            new PermissiveFieldStorageGatewayAudit(),
        );
        $registrar->build();

        return $registrar->gateway(ReservedBackendIds::SQL_COLUMN)
            ?? throw new \LogicException('The sql-column test gateway was not registered.');
    }

    /**
     * Build the minimal base spec that SqlSchemaHandler provides for the sql-column
     * path (entity-key columns only, no _data column).
     *
     * @return array<string,mixed>
     */
    private function makeBaseSpec(): array
    {
        return [
            'fields' => [
                'id'       => ['type' => 'serial', 'not null' => true],
                'uuid'     => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'bundle'   => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'title'    => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
            ],
            'primary key' => ['id'],
            'indexes'     => [],
        ];
    }
}

/** @internal */
final class SqlColumnTestBackendProvider implements IsFrameworkBackendProviderV2Interface
{
    public static ?FieldStorageBackendV2Interface $backend = null;

    public function fieldStorageBackendsV2(): array
    {
        return [self::$backend ?? throw new \LogicException('The sql-column test backend was not installed.')];
    }
}

/**
 * Minimal entity for testing — defined here (not under src/) so it is
 * autoload-dev only and does not trigger the production boot gotcha.
 */
final class TestEntity extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct(
            $values,
            'test_entity',
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle', 'langcode' => 'langcode'],
        );
    }
}
