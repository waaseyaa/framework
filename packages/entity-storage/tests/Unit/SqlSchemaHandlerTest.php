<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestConfigEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

#[CoversClass(SqlSchemaHandler::class)]
final class SqlSchemaHandlerTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }

    public function testEnsureTableCreatesTable(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('test_entity'));
    }

    public function testEnsureTableIdempotent(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);

        // Call twice — should not throw.
        $handler->ensureTable();
        $handler->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('test_entity'));
    }

    public function testSchemaHasCorrectColumns(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        $schema = $this->database->schema();

        $this->assertTrue($schema->fieldExists('test_entity', 'id'));
        $this->assertTrue($schema->fieldExists('test_entity', 'uuid'));
        $this->assertTrue($schema->fieldExists('test_entity', 'bundle'));
        $this->assertTrue($schema->fieldExists('test_entity', 'label'));
        $this->assertTrue($schema->fieldExists('test_entity', 'langcode'));
    }

    public function testGetTableName(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);

        $this->assertSame('test_entity', $handler->getTableName());
    }

    public function testAddFieldColumns(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        $handler->addFieldColumns([
            'status' => [
                'type' => 'int',
                'not null' => true,
                'default' => 1,
            ],
            'body' => [
                'type' => 'text',
                'not null' => false,
            ],
        ]);

        $schema = $this->database->schema();
        $this->assertTrue($schema->fieldExists('test_entity', 'status'));
        $this->assertTrue($schema->fieldExists('test_entity', 'body'));
    }

    public function testConfigEntitySchemaUsesVarcharId(): void
    {
        $this->ensureConfigTable();

        $schema = $this->database->schema();
        $this->assertTrue($schema->tableExists('node_type'));
        $this->assertTrue($schema->fieldExists('node_type', 'type'));
        $this->assertTrue($schema->fieldExists('node_type', 'name'));
        // Config entities should NOT have a UUID column.
        $this->assertFalse($schema->fieldExists('node_type', 'uuid'));
    }

    public function testConfigEntityCanInsertStringId(): void
    {
        $this->ensureConfigTable();

        // Verify we can insert a string ID.
        $this->database->insert('node_type')
            ->fields(['type', 'name', 'bundle', 'langcode', '_data'])
            ->values([
                'type' => 'article',
                'name' => 'Article',
                'bundle' => '',
                'langcode' => 'en',
                '_data' => '{}',
            ])
            ->execute();

        $result = $this->database->select('node_type')
            ->fields('node_type')
            ->condition('type', 'article')
            ->execute();

        $row = null;
        foreach ($result as $r) {
            $row = (array) $r;
            break;
        }

        $this->assertNotNull($row);
        $this->assertSame('article', $row['type']);
        $this->assertSame('Article', $row['name']);
    }

    public function testEnsureRevisionTableCreatesTableWithCompositePk(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $schema = $db->schema();
        $this->assertTrue($schema->tableExists('node_revision'));
        $this->assertTrue($schema->fieldExists('node_revision', 'entity_id'));
        $this->assertTrue($schema->fieldExists('node_revision', 'revision_id'));
        $this->assertTrue($schema->fieldExists('node_revision', 'revision_created'));
        $this->assertTrue($schema->fieldExists('node_revision', 'revision_log'));
        $this->assertTrue($schema->fieldExists('node_revision', '_data'));
    }

    public function testEnsureRevisionTableIsIdempotent(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureRevisionTable(); // second call should be a no-op

        $this->assertTrue($db->schema()->tableExists('node_revision'));
    }

    public function testEnsureTableAddsRevisionIdColumnForRevisionableTypes(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();

        $this->assertTrue($db->schema()->fieldExists('node', 'revision_id'));
    }

    public function testEnsureTableAddsPublishedRevisionPointerForRevisionableTypes(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();

        // Published-revision pointer is materialised alongside (and separate
        // from) the current-revision pointer for every revisionable type.
        $this->assertTrue($db->schema()->fieldExists('node', 'published_revision_id'));
    }

    public function testNonRevisionableTypesHaveNoPublishedRevisionPointer(): void
    {
        $entityType = new EntityType(
            id: 'note',
            label: 'Note',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            revisionable: false,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();

        $this->assertFalse($db->schema()->fieldExists('note', 'published_revision_id'));
    }

    public function testDeriveColumnSpecMapsTextLongUriAndEntityReference(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $m = new ReflectionMethod(SqlSchemaHandler::class, 'deriveColumnSpec');

        $textLong = new FieldDefinition(name: 'description', type: 'text_long');
        self::assertSame('text', $this->invokeDeriveColumnSpec($m, $handler, $textLong)['type']);

        $uri = new FieldDefinition(name: 'url', type: 'uri');
        $uriSpec = $this->invokeDeriveColumnSpec($m, $handler, $uri);
        self::assertSame('varchar', $uriSpec['type']);
        self::assertSame(2048, $uriSpec['length']);

        $uriCustom = new FieldDefinition(name: 'booking_url', type: 'uri', settings: ['length' => 512]);
        self::assertSame(512, $this->invokeDeriveColumnSpec($m, $handler, $uriCustom)['length']);

        // Waaseyaa cross-entity references are the destination entity's UUID
        // string, so the column is varchar (correct on column-strict backends),
        // not int. See BUGLOG B14.
        $ref = new FieldDefinition(name: 'community_id', type: 'entity_reference', targetEntityTypeId: 'node');
        self::assertSame('varchar', $this->invokeDeriveColumnSpec($m, $handler, $ref)['type']);
    }

    public function testDeriveColumnSpecLogsWarningForUnknownType(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('unknown field type'),
            self::callback(static function (array $context): bool {
                return ($context['field_type'] ?? '') === 'not_a_real_field_type'
                    && ($context['field'] ?? '') === 'weird';
            }),
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->database, null, null, $logger);
        $m = new ReflectionMethod(SqlSchemaHandler::class, 'deriveColumnSpec');
        $field = new FieldDefinition(name: 'weird', type: 'not_a_real_field_type');
        $spec = $this->invokeDeriveColumnSpec($m, $handler, $field);
        self::assertSame('text', $spec['type']);
    }

    public function testSeedRevisionsCreatesRevision1ForExistingRows(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        // Insert an existing row without a revision.
        $db->insert('node')
            ->fields(['nid', 'uuid', 'title', 'bundle', 'langcode', '_data'])
            ->values(['nid' => '1', 'uuid' => 'abc', 'title' => 'Existing', 'bundle' => 'page', 'langcode' => 'en', '_data' => '{}'])
            ->execute();

        $handler->seedRevisions();

        // Verify revision 1 was created.
        $result = $db->query('SELECT * FROM node_revision WHERE entity_id = ? AND revision_id = 1', ['1']);
        $revRow = null;
        foreach ($result as $row) {
            $revRow = (array) $row;
            break;
        }
        $this->assertNotNull($revRow);
        $this->assertSame('Existing', $revRow['title']);

        // Verify base table pointer updated.
        $result = $db->query('SELECT revision_id FROM node WHERE nid = ?', ['1']);
        foreach ($result as $row) {
            $this->assertSame(1, (int) ((array) $row)['revision_id']);
        }

        // Verify idempotent — second call is a no-op.
        $handler->seedRevisions();
        $result = $db->query('SELECT COUNT(*) as cnt FROM node_revision WHERE entity_id = ?', ['1']);
        foreach ($result as $row) {
            $this->assertSame(1, (int) ((array) $row)['cnt']);
        }
    }

    public function testDeclaredUniqueKeyPromotesBackfillsAndValidatesRuntimeSchema(): void
    {
        $baseType = new EntityType(
            id: 'unique_entity',
            label: 'Unique Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _fieldDefinitions: ['slug' => new FieldDefinition(name: 'slug', type: 'string')],
        );
        new SqlSchemaHandler($baseType, $this->database)->ensureTable();
        $this->database->insert('unique_entity')->fields(['id', 'uuid', '_data'])->values([
            'id' => '1', 'uuid' => 'unique-1',
            '_data' => json_encode(['slug' => 'water'], JSON_THROW_ON_ERROR),
        ])->execute();

        $authoritativeType = new EntityType(
            id: 'unique_entity',
            label: 'Unique Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _fieldDefinitions: ['slug' => new FieldDefinition(name: 'slug', type: 'string')],
            _storageUniqueKeys: [['name' => 'unique_entity_slug', 'fields' => ['slug']]],
        );
        $handler = new SqlSchemaHandler($authoritativeType, $this->database);
        $handler->ensureTable();
        $handler->assertRuntimeSchema();

        $this->assertSame('water', $this->database->getConnection()->fetchOne('SELECT slug FROM unique_entity WHERE id = ?', ['1']));
        $indexes = $this->database->getConnection()->createSchemaManager()->listTableIndexes('unique_entity');
        $this->assertArrayHasKey('unique_entity_slug', $indexes);
        $this->assertTrue($indexes['unique_entity_slug']->isUnique());
    }

    public function testDeclaredUniqueKeyRejectsAnUnknownField(): void
    {
        $type = new EntityType(
            id: 'invalid_unique_entity',
            label: 'Invalid Unique Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _storageUniqueKeys: [['name' => 'invalid_unique', 'fields' => ['missing']]],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('names unknown field "missing"');
        new SqlSchemaHandler($type, $this->database)->ensureTable();
    }

    public function testDeclaredSchemaTransitionRunsIdempotentlyDuringSchemaSync(): void
    {
        $type = new EntityType(
            id: 'transition_entity',
            label: 'Transition Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _storageSchemaTransitions: [TestAddSchemaColumnTransition::class],
        );
        $handler = new SqlSchemaHandler($type, $this->database);
        $handler->ensureTable();
        $handler->ensureTable();

        $this->assertTrue($this->database->schema()->fieldExists('transition_entity', 'transitioned'));
    }

    public function testDeclaredSchemaTransitionMustImplementTheTransitionContract(): void
    {
        $type = new EntityType(
            id: 'invalid_transition_entity',
            label: 'Invalid Transition Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            _storageSchemaTransitions: [\stdClass::class],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must implement');
        new SqlSchemaHandler($type, $this->database)->ensureTable();
    }

    // ------------------------------------------------------------------
    // Declared foreign-key runtime readiness (#2761: production HTTP/kernel
    // boot must fail closed with [S1-DB106] rather than repair a missing
    // declared foreign key, mirroring the existing unique-key contract).
    // ------------------------------------------------------------------

    public function testAssertRuntimeSchemaThrowsWhenDeclaredForeignKeyIsMissing(): void
    {
        $this->database->schema()->createTable('fk_ref_entity', [
            'fields' => ['id' => ['type' => 'serial']],
            'primary key' => ['id'],
        ]);

        // Base table materialized WITHOUT the declared foreign key — the
        // exact shape a coordinated schema-sync run that never reached the
        // foreign-key step (or a pre-existing install) leaves behind.
        new SqlSchemaHandler($this->entityType, $this->database)->ensureTable();

        $typeWithFk = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: $this->entityType->getKeys(),
            _foreignKeys: [[
                'name' => 'test_entity_fk_ref_fk',
                'columns' => ['id'],
                'table' => 'fk_ref_entity',
                'references' => ['id'],
            ]],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[S1-DB106]');
        $this->expectExceptionMessageMatches('/foreign key/');
        new SqlSchemaHandler($typeWithFk, $this->database)->assertRuntimeSchema();
    }

    public function testAssertRuntimeSchemaPassesWhenDeclaredForeignKeyIsPresent(): void
    {
        $this->database->schema()->createTable('fk_ref_entity', [
            'fields' => ['id' => ['type' => 'serial']],
            'primary key' => ['id'],
        ]);

        $typeWithFk = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: $this->entityType->getKeys(),
            _foreignKeys: [[
                'name' => 'test_entity_fk_ref_fk',
                'columns' => ['id'],
                'table' => 'fk_ref_entity',
                'references' => ['id'],
            ]],
        );

        // Coordinated schema sync: creates the base table AND the declared
        // foreign key together.
        $handler = new SqlSchemaHandler($typeWithFk, $this->database);
        $handler->ensureTable();

        $handler->assertRuntimeSchema();
        $this->addToAssertionCount(1);
    }

    public function testAssertRuntimeSchemaSkipsForeignKeyCheckWhenReferencedTableIsAbsent(): void
    {
        $typeWithFk = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: $this->entityType->getKeys(),
            _foreignKeys: [[
                'name' => 'test_entity_fk_ref_fk',
                'columns' => ['id'],
                'table' => 'never_created_ref_entity',
                'references' => ['id'],
            ]],
        );

        // The referenced table never exists in this test: ensureTable()
        // additively skips the foreign key (mirrors EntitySchemaSync's
        // ordering-tolerant retry across registration order), and
        // assertRuntimeSchema() must not treat that as a readiness failure —
        // the referenced entity type owns its own table's readiness.
        $handler = new SqlSchemaHandler($typeWithFk, $this->database);
        $handler->ensureTable();

        $handler->assertRuntimeSchema();
        $this->addToAssertionCount(1);
    }

    public function testAssertRuntimeSchemaSkipsForeignKeyCheckWhenEntityTypeDoesNotDeclareForeignKeys(): void
    {
        // A minimal EntityTypeInterface implementor that does NOT implement
        // EntityTypeForeignKeyDefinitionInterface — e.g. a bring-your-own
        // entity type, or one predating #2761's opt-in FK contract.
        // assertDeclaredForeignKeysReady() must treat "no FK contract" as
        // "nothing to check", not attempt to inspect declared keys.
        $type = new class implements EntityTypeInterface {
            public function id(): string
            {
                return 'no_fk_contract_entity';
            }

            public function getLabel(): string
            {
                return 'No FK Contract Entity';
            }

            public function getClass(): string
            {
                return self::class;
            }

            public function getStorageClass(): string
            {
                /** @var class-string<EntityStorageInterface> */
                return EntityStorageInterface::class;
            }

            public function getKeys(): array
            {
                return ['id' => 'id'];
            }

            public function isRevisionable(): bool
            {
                return false;
            }

            public function getRevisionDefault(): bool
            {
                return false;
            }

            public function isTranslatable(): bool
            {
                return false;
            }

            public function getBundleEntityType(): ?string
            {
                return null;
            }

            public function getConstraints(): array
            {
                return [];
            }

            /** @return array<string, FieldDefinitionInterface> */
            public function getFieldDefinitions(): array
            {
                return [];
            }

            public function getPrimaryStorageBackend(): ?string
            {
                return null;
            }

            public function getGroup(): ?string
            {
                return null;
            }

            public function getDescription(): ?string
            {
                return null;
            }

            public function getTenancy(): ?array
            {
                return null;
            }
        };

        $handler = new SqlSchemaHandler($type, $this->database);
        $handler->ensureTable();

        // Must not throw even though nothing ever created a foreign key —
        // the entity type never declared any, so there is nothing to be
        // ready.
        $handler->assertRuntimeSchema();
        $this->addToAssertionCount(1);
    }

    public function testAssertRuntimeSchemaSkipsForeignKeyCheckWhenSchemaDriverLacksForeignKeySupport(): void
    {
        // Base table materialized normally via the real DBAL-backed schema.
        new SqlSchemaHandler($this->entityType, $this->database)->ensureTable();

        // Wrap the real database so schema() reports a SchemaInterface that
        // does NOT implement ForeignKeySchemaInterface — the shape of a
        // storage backend/driver with no portable FK introspection support.
        // assertDeclaredForeignKeysReady() must skip the check rather than
        // fail closed or fatal on a missing method.
        $database = new class ($this->database) implements DatabaseInterface {
            public function __construct(private readonly DBALDatabase $inner) {}

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $this->inner->select($table, $alias);
            }

            public function insert(string $table): InsertInterface
            {
                return $this->inner->insert($table);
            }

            public function update(string $table): UpdateInterface
            {
                return $this->inner->update($table);
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }

            public function schema(): SchemaInterface
            {
                $inner = $this->inner->schema();

                return new class ($inner) implements SchemaInterface {
                    public function __construct(private readonly SchemaInterface $inner) {}

                    public function tableExists(string $table): bool
                    {
                        return $this->inner->tableExists($table);
                    }

                    public function fieldExists(string $table, string $field): bool
                    {
                        return $this->inner->fieldExists($table, $field);
                    }

                    public function createTable(string $name, array $spec): void
                    {
                        $this->inner->createTable($name, $spec);
                    }

                    public function dropTable(string $table): void
                    {
                        $this->inner->dropTable($table);
                    }

                    public function addField(string $table, string $field, array $spec): void
                    {
                        $this->inner->addField($table, $field, $spec);
                    }

                    public function dropField(string $table, string $field): void
                    {
                        $this->inner->dropField($table, $field);
                    }

                    public function addIndex(string $table, string $name, array $fields): void
                    {
                        $this->inner->addIndex($table, $name, $fields);
                    }

                    public function dropIndex(string $table, string $name): void
                    {
                        $this->inner->dropIndex($table, $name);
                    }

                    public function addUniqueKey(string $table, string $name, array $fields): void
                    {
                        $this->inner->addUniqueKey($table, $name, $fields);
                    }

                    public function addPrimaryKey(string $table, array $fields): void
                    {
                        $this->inner->addPrimaryKey($table, $fields);
                    }

                    /** @return list<string> */
                    public function listTableNames(): array
                    {
                        return $this->inner->listTableNames();
                    }
                };
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->inner->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };

        $handler = new SqlSchemaHandler($this->entityType, $database);

        // Must not throw even though the entity type declares no foreign
        // keys of its own here — the point under test is that a schema
        // driver without ForeignKeySchemaInterface support short-circuits
        // the check entirely rather than erroring.
        $handler->assertRuntimeSchema();
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeDeriveColumnSpec(ReflectionMethod $method, SqlSchemaHandler $handler, FieldDefinitionInterface $field): array
    {
        /** @var array<string, mixed> */
        return $method->invoke($handler, $field);
    }

    private function ensureConfigTable(): void
    {
        $configType = new EntityType(
            id: 'node_type',
            label: 'Content Type',
            class: TestConfigEntity::class,
            keys: [
                'id' => 'type',
                'label' => 'name',
                'bundle' => 'bundle',
                'langcode' => 'langcode',
            ],
        );

        $handler = new SqlSchemaHandler($configType, $this->database);
        $handler->ensureTable();
    }

    // ------------------------------------------------------------------
    // revision_author column (mission revision-audit-provenance-01KTWY5V
    // FR-003, contract revision-author.md clauses 11–12)
    // ------------------------------------------------------------------

    private function revisionableType(string $id = 'node', bool $translatable = false): EntityType
    {
        $keys = ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
        if ($translatable) {
            $keys['langcode'] = 'langcode';
            $keys['default_langcode'] = 'default_langcode';
        }

        return new EntityType(
            id: $id,
            label: 'Content',
            // Translatable types must implement TranslatableInterface;
            // TestRevisionableEntity (ContentEntityBase) satisfies both axes.
            class: \Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity::class,
            keys: $keys,
            revisionable: true,
            revisionDefault: true,
            translatable: $translatable,
        );
    }

    /**
     * @return array<string, mixed> Pre-mission revision-table spec (no revision_author).
     */
    private function preMissionRevisionTableSpec(bool $translation = false): array
    {
        $fields = [
            'entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
            'revision_id' => ['type' => 'int', 'not null' => true],
            'revision_created' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
            'revision_log' => ['type' => 'text', 'not null' => false],
            '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
        ];
        $primaryKey = ['entity_id', 'revision_id'];
        if ($translation) {
            $fields = array_slice($fields, 0, 1, true)
                + ['langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true]]
                + $fields;
            $primaryKey = ['entity_id', 'langcode', 'revision_id'];
        }

        return ['fields' => $fields, 'primary key' => $primaryKey, 'indexes' => []];
    }

    public function testNewRevisionTableSpecIncludesRevisionAuthor(): void
    {
        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($this->revisionableType(), $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $this->assertTrue($db->schema()->fieldExists('node_revision', 'revision_author'));
    }

    public function testNewTranslationRevisionTableSpecIncludesRevisionAuthor(): void
    {
        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($this->revisionableType('node', translatable: true), $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureTranslationRevisionTable();

        $this->assertTrue($db->schema()->fieldExists('node__translation__revision', 'revision_author'));
    }

    public function testEnsureRevisionTableAddsAuthorColumnToPreExistingTable(): void
    {
        // Pre-mission-shaped revision table: created WITHOUT revision_author.
        $db = DBALDatabase::createSqlite();
        $db->schema()->createTable('node_revision', $this->preMissionRevisionTableSpec());
        $this->assertFalse($db->schema()->fieldExists('node_revision', 'revision_author'));

        $handler = new SqlSchemaHandler($this->revisionableType(), $db);
        $handler->ensureRevisionTable();

        $this->assertTrue(
            $db->schema()->fieldExists('node_revision', 'revision_author'),
            'The additive arm must add revision_author to a pre-existing revision table.',
        );

        // Idempotent: a second run is a no-op (no throw, column still present).
        $handler->ensureRevisionTable();
        $this->assertTrue($db->schema()->fieldExists('node_revision', 'revision_author'));
    }

    public function testEnsureTranslationRevisionTableAddsAuthorColumnToPreExistingTable(): void
    {
        $db = DBALDatabase::createSqlite();
        $db->schema()->createTable('node__translation__revision', $this->preMissionRevisionTableSpec(translation: true));
        $this->assertFalse($db->schema()->fieldExists('node__translation__revision', 'revision_author'));

        $handler = new SqlSchemaHandler($this->revisionableType('node', translatable: true), $db);
        $handler->ensureTranslationRevisionTable();

        $this->assertTrue(
            $db->schema()->fieldExists('node__translation__revision', 'revision_author'),
            'The translation-revision sibling must get the same additive treatment.',
        );

        $handler->ensureTranslationRevisionTable();
        $this->assertTrue($db->schema()->fieldExists('node__translation__revision', 'revision_author'));
    }

    public function testAdditiveAuthorArmSucceedsOnDatabaseContainingFts5Tables(): void
    {
        // #1653 regression (alpha.205): the additive arm used
        // DBALSchema::addField(), whose whole-schema introspection throws on
        // databases containing FTS5 virtual tables — their shadow tables
        // (_idx/_content/_data/...) have typeless columns that DBAL 4.4's
        // SQLite column parser rejects (TypeError: strtolower(null)). The
        // arm must use a targeted ALTER that never lists other tables.
        $db = DBALDatabase::createSqlite();
        $db->query('CREATE VIRTUAL TABLE search_index USING fts5(title, body)');

        // Pre-existing revision table without revision_author → the additive
        // arm runs (not the createTable path).
        $db->schema()->createTable('node_revision', $this->preMissionRevisionTableSpec());
        $this->assertFalse($db->schema()->fieldExists('node_revision', 'revision_author'));

        $handler = new SqlSchemaHandler($this->revisionableType(), $db);
        $handler->ensureRevisionTable();

        $this->assertTrue(
            $db->schema()->fieldExists('node_revision', 'revision_author'),
            'The additive arm must add revision_author without tripping over FTS5 shadow tables.',
        );

        // Idempotent on the same FTS5-bearing database.
        $handler->ensureRevisionTable();
        $this->assertTrue($db->schema()->fieldExists('node_revision', 'revision_author'));
    }

    public function testAdditiveAuthorArmTouchesNoOtherColumnAndNoRow(): void
    {
        $db = DBALDatabase::createSqlite();
        $db->schema()->createTable('node_revision', $this->preMissionRevisionTableSpec());
        $db->insert('node_revision')
            ->fields(['entity_id', 'revision_id', 'revision_created', 'revision_log', '_data'])
            ->values([
                'entity_id' => '1',
                'revision_id' => 1,
                'revision_created' => '2026-01-01 00:00:00',
                'revision_log' => 'pre-mission row',
                '_data' => '{}',
            ])
            ->execute();

        $handler = new SqlSchemaHandler($this->revisionableType(), $db);
        $handler->ensureRevisionTable();

        // C-001: no row rewritten — the pre-existing row is intact and its
        // new author column reads SQL NULL.
        $rows = [];
        foreach ($db->query('SELECT * FROM node_revision') as $row) {
            $rows[] = (array) $row;
        }
        $this->assertCount(1, $rows);
        $this->assertSame('pre-mission row', $rows[0]['revision_log']);
        $this->assertSame('2026-01-01 00:00:00', $rows[0]['revision_created']);
        $this->assertNull($rows[0]['revision_author']);
    }
}

final class TestAddSchemaColumnTransition implements \Waaseyaa\EntityStorage\Schema\EntityStorageSchemaTransitionInterface
{
    public function __construct(\Waaseyaa\Database\DatabaseInterface $_database) {}

    public function apply(\Waaseyaa\Database\DatabaseInterface $database, string $table): void
    {
        if (!$database->schema()->fieldExists($table, 'transitioned')) {
            $database->schema()->addField($table, 'transitioned', ['type' => 'int', 'not null' => true, 'default' => 0]);
        }
    }
}
