<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Schema;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Schema\RevisionTableBuilder;
use Waaseyaa\Field\FieldDefinition;

/**
 * Unit tests for RevisionTableBuilder's two-axis (M-004 / WP01) emission path.
 *
 * Focuses on the schema-emitter contract details that the abstract contract
 * test cannot cover at the cross-backend level: FR-001 logical-PK uniqueness,
 * FR-008 invariant scaffolding columns, idempotency edge cases, and the
 * partition rule (FR-004) at the column-detail level.
 */
#[CoversClass(RevisionTableBuilder::class)]
final class RevisionTableBuilderTwoAxisTest extends TestCase
{
    private function makeSqlite(): DBALDatabase
    {
        return new DBALDatabase(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );
    }

    private function makeTwoAxisType(string $id = 'teaching'): EntityType
    {
        return new EntityType(
            id: $id,
            label: ucfirst($id),
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'tid',
                'uuid'             => 'uuid',
                'revision'         => 'vid',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: true,
            translatable: true,
            primaryStorageBackend: 'sql-column',
        );
    }

    /**
     * @return list<string>
     */
    private function columnsOf(DBALDatabase $database, string $table): array
    {
        $cols = [];
        $rows = iterator_to_array($database->query('PRAGMA table_info(' . $table . ')'));
        foreach ($rows as $row) {
            $cols[] = (string) $row['name'];
        }
        return $cols;
    }

    /**
     * @return list<array{name: string, unique: bool}>
     */
    private function indexesOf(DBALDatabase $database, string $table): array
    {
        $rows = iterator_to_array($database->query('PRAGMA index_list(' . $table . ')'));
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name'   => (string) $row['name'],
                'unique' => (bool) $row['unique'],
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // FR-001 / FR-002 — composite-PK shape via UNIQUE + surrogate vid
    // -------------------------------------------------------------------------

    #[Test]
    public function translation_revision_table_has_composite_unique_on_entity_langcode_vid(): void
    {
        $db = $this->makeSqlite();
        (new RevisionTableBuilder($db))->buildTwoAxis(
            $this->makeTwoAxisType('teaching'),
            'sql-column',
            [new FieldDefinition(name: 'title', type: 'string', translatable: true)],
        );

        $indexes = $this->indexesOf($db, 'teaching__translation__revision');

        // At least one UNIQUE index must exist; the composite logical PK.
        $hasUnique = false;
        foreach ($indexes as $ix) {
            if ($ix['unique']) {
                $hasUnique = true;
                break;
            }
        }
        self::assertTrue(
            $hasUnique,
            'FR-001: translation-revision table must carry a UNIQUE index expressing the logical PK',
        );
    }

    #[Test]
    public function translation_revision_carries_revision_metadata_columns(): void
    {
        $db = $this->makeSqlite();
        (new RevisionTableBuilder($db))->buildTwoAxis(
            $this->makeTwoAxisType('teaching'),
            'sql-column',
            [],
        );

        $cols = $this->columnsOf($db, 'teaching__translation__revision');
        self::assertContains('revision_created_at', $cols);
        self::assertContains('revision_author', $cols);
        self::assertContains('revision_log', $cols);
    }

    // -------------------------------------------------------------------------
    // FR-004 — partition rule: non-translatable fields off the translation
    //          revision table.
    // -------------------------------------------------------------------------

    #[Test]
    public function non_translatable_field_does_not_appear_on_translation_revision_table(): void
    {
        $db = $this->makeSqlite();
        (new RevisionTableBuilder($db))->buildTwoAxis(
            $this->makeTwoAxisType('teaching'),
            'sql-column',
            [
                new FieldDefinition(name: 'title', type: 'string', translatable: true),
                new FieldDefinition(name: 'author_id', type: 'integer'),
            ],
        );

        $transCols = $this->columnsOf($db, 'teaching__translation__revision');
        self::assertContains('title', $transCols);
        self::assertNotContains(
            'author_id',
            $transCols,
            'FR-004: non-translatable fields must live on __revision only',
        );

        $revCols = $this->columnsOf($db, 'teaching__revision');
        self::assertContains('author_id', $revCols);
        self::assertNotContains('title', $revCols, 'translatable field must not appear on __revision');
    }

    // -------------------------------------------------------------------------
    // FR-006 — translatable + vector backend = boot-time rejection.
    // -------------------------------------------------------------------------

    #[Test]
    public function translatable_field_on_vector_backend_throws_at_boot(): void
    {
        $db = $this->makeSqlite();
        $builder = new RevisionTableBuilder($db);
        $fields = [
            (new FieldDefinition(name: 'embedding', type: 'text', translatable: true))
                ->storedIn('vector'),
        ];

        try {
            $builder->buildTwoAxis($this->makeTwoAxisType('teaching'), 'sql-column', $fields);
            self::fail('expected RuntimeException for translatable field on vector backend');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unsupportedTwoAxisField', $e->getMessage());
            self::assertStringContainsString('embedding', $e->getMessage());
            self::assertStringContainsString('vector', $e->getMessage());
        }

        // FR-006 guard must run before any DDL: no tables created on failure.
        self::assertFalse($db->schema()->tableExists('teaching__revision'));
        self::assertFalse($db->schema()->tableExists('teaching__translation__revision'));
    }

    #[Test]
    public function translatable_field_with_sql_column_explicit_backend_is_accepted(): void
    {
        $db = $this->makeSqlite();
        $builder = new RevisionTableBuilder($db);
        $fields = [
            (new FieldDefinition(name: 'title', type: 'string', translatable: true))
                ->storedIn('sql-column'),
        ];

        $builder->buildTwoAxis($this->makeTwoAxisType('teaching'), 'sql-column', $fields);

        $cols = $this->columnsOf($db, 'teaching__translation__revision');
        self::assertContains('title', $cols);
    }

    // -------------------------------------------------------------------------
    // FR-005 / FR-008 — schema readiness for single-step fallback + entity-level
    //                    primary current-revision invariant.
    // -------------------------------------------------------------------------

    #[Test]
    public function revision_table_carries_id_column_so_fallback_can_join_by_entity_id(): void
    {
        // FR-005 / FR-008: non-translatable reads from non-default langcodes
        // single-step-fallback to <entity>__revision keyed by entity_id + vid.
        // The schema MUST therefore preserve the soft FK id column.
        $db = $this->makeSqlite();
        (new RevisionTableBuilder($db))->buildTwoAxis(
            $this->makeTwoAxisType('teaching'),
            'sql-column',
            [new FieldDefinition(name: 'author_id', type: 'integer')],
        );

        $cols = $this->columnsOf($db, 'teaching__revision');
        self::assertContains('tid', $cols, 'FR-005/FR-008: __revision must carry entity-id column for fallback');
        self::assertContains('vid', $cols);
    }

    // -------------------------------------------------------------------------
    // sql-blob branch — translation-revision table emits _data blob
    // -------------------------------------------------------------------------

    #[Test]
    public function sql_blob_two_axis_emits_data_blob_column_on_translation_revision_table(): void
    {
        $db = $this->makeSqlite();

        $type = new EntityType(
            id: 'teaching',
            label: 'Teaching',
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'tid',
                'uuid'             => 'uuid',
                'revision'         => 'vid',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: true,
            translatable: true,
            primaryStorageBackend: 'sql-blob',
        );

        (new RevisionTableBuilder($db))->buildTwoAxis(
            $type,
            'sql-blob',
            [new FieldDefinition(name: 'title', type: 'string', translatable: true)],
        );

        $transCols = $this->columnsOf($db, 'teaching__translation__revision');
        self::assertContains('_data', $transCols);
        self::assertNotContains('title', $transCols, 'sql-blob path stores fields in _data');
    }

    // -------------------------------------------------------------------------
    // Idempotency — second call does not recreate tables.
    // -------------------------------------------------------------------------

    #[Test]
    public function second_call_does_not_throw_when_tables_exist(): void
    {
        $db = $this->makeSqlite();
        $builder = new RevisionTableBuilder($db);
        $type = $this->makeTwoAxisType('teaching');

        $builder->buildTwoAxis($type, 'sql-column', []);
        $builder->buildTwoAxis($type, 'sql-column', []);

        self::assertTrue($db->schema()->tableExists('teaching__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__translation__revision'));
    }
}
