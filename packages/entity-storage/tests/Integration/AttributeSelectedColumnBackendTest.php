<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Exception\UnmaterializableIndexException;
use Waaseyaa\Foundation\Kernel\Preflight\LiveEntitySchemaFingerprint;
use Waaseyaa\EntityStorage\Tests\Fixtures\AttributeColumnEntity;
use Waaseyaa\Field\FieldStorage;

/**
 * #2157: an attribute-defined entity type can select the sql-column backend and
 * declare indexed fields, and those declarations become real columns and real
 * indexes.
 *
 * Before this change the two requirements were mutually unreachable:
 * `EntityType::fromClass()` could not select a backend, only sql-column
 * materialises columns, and the constructor slot that could supply both is
 * `@internal`. A field declared `FieldStorage::Column` on the resulting
 * sql-blob type silently landed in `_data` with no index, and Rule G validation
 * passed because it checks the declaration rather than the physical shape.
 */
#[CoversNothing]
final class AttributeSelectedColumnBackendTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        EntityType::clearFromClassCache();
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_column(
            $this->database->getConnection()->fetchAllAssociative("PRAGMA table_info({$table})"),
            'name',
        );
    }

    /** @return list<string> */
    private function indexes(string $table): array
    {
        return array_column(
            $this->database->getConnection()->fetchAllAssociative("PRAGMA index_list({$table})"),
            'name',
        );
    }

    /** @return list<string> Columns covered by $index. */
    private function indexColumns(string $index): array
    {
        return array_column(
            $this->database->getConnection()->fetchAllAssociative("PRAGMA index_info({$index})"),
            'name',
        );
    }

    private function sync(EntityType ...$types): void
    {
        new EntitySchemaSync($this->database)->syncAll($types);
    }

    #[Test]
    public function an_attribute_defined_entity_can_select_the_column_backend(): void
    {
        $type = EntityType::fromClass(AttributeColumnEntity::class);

        self::assertSame(
            ReservedBackendIds::SQL_COLUMN,
            $type->getPrimaryStorageBackend(),
            'the attribute must carry the backend selection through fromClass()',
        );
    }

    #[Test]
    public function declared_column_fields_become_real_columns(): void
    {
        $this->sync(EntityType::fromClass(AttributeColumnEntity::class));

        $columns = $this->columns('attribute_column_entity');

        foreach (['title', 'source_key', 'last_seen', 'note'] as $field) {
            self::assertContains($field, $columns, "$field declares Column storage and must be materialised");
        }
    }

    #[Test]
    public function on_the_column_backend_every_declared_field_is_materialised_and_there_is_no_blob(): void
    {
        // Pre-existing behaviour, pinned here because it surprises people and
        // #2157 makes the sql-column backend reachable for the first time from
        // an attribute-defined type.
        //
        // `EntitySchemaSync::syncAll()` selects entity-level fields purely by
        // backend, not by each field's declared `stored`, so on sql-column ALL
        // declared fields become columns and no `_data` blob is created at all.
        // FieldStorage::Data therefore has no effect on this backend.
        //
        // This is deliberately NOT changed here: altering it would change
        // behaviour for any existing sql-column type, which is outside the scope
        // of #2157. It is documented in entity-system.md and left as a follow-up.
        $this->sync(EntityType::fromClass(AttributeColumnEntity::class));

        $columns = $this->columns('attribute_column_entity');

        self::assertContains('payload', $columns, 'on sql-column, a Data-declared field is still materialised');
        self::assertNotContains('_data', $columns, 'the sql-column backend creates no blob column');
    }

    #[Test]
    public function the_blob_backend_still_stores_non_key_fields_in_data(): void
    {
        // The complementary half, so the two backends are pinned against each
        // other and a future change cannot quietly converge them.
        $this->sync(EntityType::fromClass(LegacyBlobEntity::class));

        $columns = $this->columns('legacy_blob_entity');

        self::assertContains('_data', $columns);
        self::assertNotContains('facet', $columns);
    }

    #[Test]
    public function indexed_fields_get_real_indexes(): void
    {
        $this->sync(EntityType::fromClass(AttributeColumnEntity::class));

        $covered = [];
        foreach ($this->indexes('attribute_column_entity') as $index) {
            foreach ($this->indexColumns($index) as $column) {
                $covered[$column][] = $index;
            }
        }

        self::assertArrayHasKey('source_key', $covered, 'an indexed field must receive a physical index');
        self::assertArrayHasKey('last_seen', $covered, 'an indexed field must receive a physical index');
    }

    #[Test]
    public function multiple_indexed_fields_get_distinct_indexes(): void
    {
        $this->sync(EntityType::fromClass(AttributeColumnEntity::class));

        $sourceKeyIndexes = [];
        $lastSeenIndexes = [];
        foreach ($this->indexes('attribute_column_entity') as $index) {
            $columns = $this->indexColumns($index);
            if ($columns === ['source_key']) {
                $sourceKeyIndexes[] = $index;
            }
            if ($columns === ['last_seen']) {
                $lastSeenIndexes[] = $index;
            }
        }

        self::assertCount(1, $sourceKeyIndexes);
        self::assertCount(1, $lastSeenIndexes);
        self::assertNotSame($sourceKeyIndexes[0], $lastSeenIndexes[0], 'each indexed field needs its own index');
    }

    #[Test]
    public function a_column_field_that_is_not_indexed_gets_no_index(): void
    {
        $this->sync(EntityType::fromClass(AttributeColumnEntity::class));

        foreach ($this->indexes('attribute_column_entity') as $index) {
            self::assertNotSame(['note'], $this->indexColumns($index), 'only declared-indexed fields get indexes');
        }
    }

    #[Test]
    public function schema_synchronisation_is_idempotent(): void
    {
        $type = EntityType::fromClass(AttributeColumnEntity::class);

        $this->sync($type);
        $columnsAfterFirst = $this->columns('attribute_column_entity');
        $indexesAfterFirst = $this->indexes('attribute_column_entity');

        // Repeated db:init must not duplicate columns, duplicate indexes, or throw.
        $this->sync($type);
        $this->sync($type);

        self::assertSame($columnsAfterFirst, $this->columns('attribute_column_entity'));
        self::assertSame($indexesAfterFirst, $this->indexes('attribute_column_entity'));
    }

    // ------------------------------------------------------------------
    // Backward compatibility: the default must not move
    // ------------------------------------------------------------------

    #[Test]
    public function an_attribute_entity_without_the_new_argument_stays_on_the_blob_backend(): void
    {
        $type = EntityType::fromClass(LegacyBlobEntity::class);

        self::assertNull(
            $type->getPrimaryStorageBackend(),
            'omitting storageBackend must preserve the pre-#2157 default',
        );

        $this->sync($type);

        $columns = $this->columns('legacy_blob_entity');
        self::assertContains('_data', $columns);
        self::assertNotContains(
            'facet',
            $columns,
            'a Column-declared field on a blob-backed type keeps its existing behaviour: this is the shape shipped apps rely on',
        );
    }

    // ------------------------------------------------------------------
    // The silent mismatch becomes loud
    // ------------------------------------------------------------------

    #[Test]
    public function an_indexed_field_on_a_blob_backed_type_fails_schema_initialisation(): void
    {
        $this->expectException(UnmaterializableIndexException::class);
        // The message must name the type, the field, and the fix.
        $this->expectExceptionMessageMatches('/unmaterializable_index_entity.*facet|facet.*unmaterializable_index_entity/s');

        $this->sync(EntityType::fromClass(UnmaterializableIndexEntity::class));
    }

    #[Test]
    public function the_unmaterializable_index_error_names_the_remedy(): void
    {
        try {
            $this->sync(EntityType::fromClass(UnmaterializableIndexEntity::class));
            self::fail('expected UnmaterializableIndexException');
        } catch (UnmaterializableIndexException $e) {
            self::assertStringContainsString(
                'storageBackend',
                $e->getMessage(),
                'the error must tell the developer how to fix it, not just that it is wrong',
            );
            self::assertStringContainsString(ReservedBackendIds::SQL_COLUMN, $e->getMessage());
        }
    }

    #[Test]
    public function an_indexed_data_stored_field_is_rejected_by_api_contract(): void
    {
        // An API-contract rejection, NOT a physical-impossibility claim:
        // `indexed: true` is permitted only with FieldStorage::Column, so that
        // asking for an index is always an explicit declaration of indexable
        // intent. Note that this fixture is on the sql-column backend, which
        // WOULD materialise a column for the Data-stored field — see
        // on_the_column_backend_every_declared_field_is_materialised... — so
        // the rejection cannot be justified by impossibility. It is a rule
        // about the declaration, enforced at metadata-read time.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/indexed.*FieldStorage::Data|FieldStorage::Data.*indexed/s');

        EntityType::fromClass(IndexedBlobFieldEntity::class);
    }

    #[Test]
    public function an_unknown_storage_backend_is_rejected_with_an_actionable_message(): void
    {
        // The attribute is the public API surface for backend selection, so a
        // typo must fail at the declaration with a message that names the
        // offending value and the legal set — not resolve to some default and
        // silently leave the type blob-backed, which is the exact silent
        // failure mode #2157 exists to close.
        try {
            new ContentEntityType(id: 'typo_backend_entity', label: 'Typo', storageBackend: 'sql_column');
            self::fail('an unknown storageBackend must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('typo_backend_entity', $e->getMessage(), 'names the entity type');
            self::assertStringContainsString('sql_column', $e->getMessage(), 'quotes the rejected value');
            self::assertStringContainsString(
                PrimaryStorageBackend::SQL_COLUMN,
                $e->getMessage(),
                'lists the legal values so the developer can see the correct spelling',
            );
            self::assertStringContainsString(PrimaryStorageBackend::SQL_BLOB, $e->getMessage());
        }

        // The empty default must NOT be caught by that guard — omitting the
        // argument is how every existing entity type keeps the blob default.
        $declared = new ContentEntityType(id: 'ok_entity', label: 'Ok');
        self::assertSame('', $declared->storageBackend);
    }

    #[Test]
    public function the_production_preflight_fingerprint_includes_the_materialised_columns(): void
    {
        // The deployment preflight hashes the entity-storage shape, so a
        // sql-column type's real columns must participate. If they did not, an
        // app could add or reshape physical columns without staling the
        // committed artifact, which is exactly the failure #2143 closed.
        $this->sync(EntityType::fromClass(AttributeColumnEntity::class));

        $withColumns = LiveEntitySchemaFingerprint::compute($this->database, ['attribute_column_entity']);

        // A different physical shape must produce a different fingerprint.
        $this->database->getConnection()->executeStatement(
            'ALTER TABLE attribute_column_entity ADD COLUMN added_later TEXT',
        );
        $afterChange = LiveEntitySchemaFingerprint::compute($this->database, ['attribute_column_entity']);

        self::assertNotSame(
            $withColumns,
            $afterChange,
            'the preflight fingerprint must react to the materialised column shape',
        );

        // And the shape genuinely contains the declared facets, not just the keys.
        $columns = $this->columns('attribute_column_entity');
        self::assertContains('source_key', $columns);
        self::assertContains('last_seen', $columns);
    }

    #[Test]
    public function the_entity_package_backend_constants_match_the_storage_package(): void
    {
        // PrimaryStorageBackend mirrors ReservedBackendIds because packages/entity
        // cannot depend on packages/entity-storage. This pins them together so the
        // mirror cannot drift.
        self::assertSame(ReservedBackendIds::SQL_BLOB, PrimaryStorageBackend::SQL_BLOB);
        self::assertSame(ReservedBackendIds::SQL_COLUMN, PrimaryStorageBackend::SQL_COLUMN);
    }
}

/**
 * A pre-#2157 shaped entity: attributes only, no backend selection.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(id: 'legacy_blob_entity', label: 'Legacy blob entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class LegacyBlobEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column)]
    public string $title = '';

    #[Field(required: false, label: 'Facet', stored: FieldStorage::Column)]
    public string $facet = '';
}

/**
 * Declares an index the resolved backend will not create: the type stays on the
 * blob backend, so the index is unmaterializable and schema sync must say so.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(id: 'unmaterializable_index_entity', label: 'Unmaterializable index entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class UnmaterializableIndexEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column)]
    public string $title = '';

    #[Field(required: false, label: 'Facet', stored: FieldStorage::Column, indexed: true)]
    public string $facet = '';
}

/**
 * Declares an index on a Data-stored field, which the `#[Field]` API contract
 * forbids. Deliberately sits on the sql-column backend to make the point that
 * the rejection is a rule about the declaration, not a physical impossibility:
 * this backend would have materialised the column.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(id: 'indexed_blob_field_entity', label: 'Indexed blob field entity', storageBackend: PrimaryStorageBackend::SQL_COLUMN)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class IndexedBlobFieldEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column)]
    public string $title = '';

    #[Field(required: false, type: 'text', label: 'Payload', stored: FieldStorage::Data, indexed: true)]
    public string $payload = '';
}
