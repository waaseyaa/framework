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
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler;
use Waaseyaa\Field\FieldDefinition;

/**
 * Unit tests for {@see TranslationSchemaHandler::syncTwoAxis()} (M-004 / WP02).
 *
 * Asserts the WP02 two-axis blob emission path:
 *   - Materialises both `<entity>__revision` and `<entity>__translation__revision`.
 *   - For sql-blob primary backend, each revision table carries a `_data`
 *     JSON column rather than per-field columns.
 *   - Sql-column primary backend continues to emit one column per field on
 *     the appropriate side of the translatable / non-translatable partition
 *     (FR-004).
 *   - Single-axis entity types are no-ops (R-A regression gate).
 *   - Idempotent across repeat invocations.
 */
#[CoversClass(TranslationSchemaHandler::class)]
final class TranslationSchemaHandlerTwoAxisTest extends TestCase
{
    private function makeSqliteDatabase(): DBALDatabase
    {
        return new DBALDatabase(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );
    }

    private function makeTwoAxisType(string $backend, string $id = 'teaching'): EntityType
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
            primaryStorageBackend: $backend,
        );
    }

    /**
     * @return list<string>
     */
    private function columnsOf(DBALDatabase $db, string $table): array
    {
        $cols = [];
        foreach ($db->query('PRAGMA table_info(' . $table . ')') as $row) {
            $cols[] = (string) $row['name'];
        }
        return $cols;
    }

    #[Test]
    public function sync_two_axis_emits_both_revision_tables_for_sql_blob(): void
    {
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);
        $type = $this->makeTwoAxisType(ReservedBackendIds::SQL_BLOB);

        $fields = [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'body', type: 'text', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
        ];

        $handler->syncTwoAxis($type, $fields);

        self::assertTrue($db->schema()->tableExists('teaching__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__translation__revision'));

        // FR-003: sql-blob carries a `_data` JSON column on both revision tables.
        self::assertContains('_data', $this->columnsOf($db, 'teaching__revision'));
        self::assertContains('_data', $this->columnsOf($db, 'teaching__translation__revision'));
    }

    #[Test]
    public function sync_two_axis_emits_both_revision_tables_for_sql_column(): void
    {
        // Backend-parity check: the same handler entry point must materialise
        // the column-mode shape too (so callers can rely on syncTwoAxis() as
        // the canonical two-axis trigger regardless of primary backend).
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);
        $type = $this->makeTwoAxisType(ReservedBackendIds::SQL_COLUMN);

        $fields = [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
        ];

        $handler->syncTwoAxis($type, $fields);

        $revCols = $this->columnsOf($db, 'teaching__revision');
        $transRevCols = $this->columnsOf($db, 'teaching__translation__revision');

        // FR-004 partition: non-translatable on __revision, translatable on
        // __translation__revision; never duplicated.
        self::assertContains('author_id', $revCols);
        self::assertNotContains('author_id', $transRevCols);
        self::assertContains('title', $transRevCols);
        self::assertNotContains('title', $revCols);
    }

    #[Test]
    public function sync_two_axis_emits_logical_pk_index_on_translation_revision(): void
    {
        // FR-001: composite-uniqueness on (entity_id, langcode, vid).
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);
        $type = $this->makeTwoAxisType(ReservedBackendIds::SQL_BLOB);

        $handler->syncTwoAxis($type, []);

        $indexes = [];
        foreach ($db->query('PRAGMA index_list(teaching__translation__revision)') as $row) {
            $indexes[(string) $row['name']] = (int) ($row['unique'] ?? 0);
        }

        $logicalPk = array_keys(array_filter(
            $indexes,
            static fn (int $unique, string $name): bool
                => $unique === 1 && str_contains($name, '_logical_pk'),
            \ARRAY_FILTER_USE_BOTH,
        ));

        self::assertNotEmpty(
            $logicalPk,
            'expected a UNIQUE logical-pk index over (entity_id, langcode, vid)',
        );
    }

    #[Test]
    public function sync_two_axis_is_a_noop_for_single_axis_translatable_only(): void
    {
        // R-A regression gate: single-axis types continue to use the M-006
        // sync() path; syncTwoAxis() must not touch them.
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);

        $type = new EntityType(
            id: 'note',
            label: 'Note',
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'nid',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            primaryStorageBackend: ReservedBackendIds::SQL_BLOB,
        );

        $handler->syncTwoAxis($type, []);

        self::assertFalse(
            $db->schema()->tableExists('note__translation__revision'),
            'single-axis translatable-only must not get a translation-revision table',
        );
        self::assertFalse(
            $db->schema()->tableExists('note__revision'),
            'single-axis translatable-only must not get a revision table',
        );
    }

    #[Test]
    public function sync_two_axis_is_a_noop_for_single_axis_revisionable_only(): void
    {
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);

        $type = new EntityType(
            id: 'post',
            label: 'Post',
            class: \stdClass::class,
            keys: ['id' => 'pid', 'revision' => 'vid'],
            revisionable: true,
            translatable: false,
            primaryStorageBackend: ReservedBackendIds::SQL_BLOB,
        );

        $handler->syncTwoAxis($type, []);

        self::assertFalse($db->schema()->tableExists('post__translation__revision'));
        self::assertFalse($db->schema()->tableExists('post__revision'));
    }

    #[Test]
    public function sync_two_axis_is_idempotent(): void
    {
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);
        $type = $this->makeTwoAxisType(ReservedBackendIds::SQL_BLOB);

        $fields = [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
        ];

        $handler->syncTwoAxis($type, $fields);
        // Second call must not throw.
        $handler->syncTwoAxis($type, $fields);

        self::assertTrue($db->schema()->tableExists('teaching__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__translation__revision'));
    }

    #[Test]
    public function sync_two_axis_rejects_translatable_field_on_vector_backend(): void
    {
        // FR-006: vector-routed translatable fields raise at boot.
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);
        $type = $this->makeTwoAxisType(ReservedBackendIds::SQL_BLOB);

        $fields = [
            (new FieldDefinition(name: 'embedding', type: 'text', translatable: true))
                ->storedIn('vector'),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupportedTwoAxisField');

        $handler->syncTwoAxis($type, $fields);
    }

    #[Test]
    public function single_axis_translatable_sync_unchanged_when_handler_extended(): void
    {
        // R-A regression gate: the existing M-006 sync() path for a
        // single-axis translatable sql-column type is byte-for-byte
        // unchanged by the addition of syncTwoAxis().
        $db = $this->makeSqliteDatabase();
        $handler = new TranslationSchemaHandler($db);

        $type = new EntityType(
            id: 'topic',
            label: 'Topic',
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'id',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            primaryStorageBackend: ReservedBackendIds::SQL_COLUMN,
        );

        // Pre-create the primary table so the sql-column translation handler
        // can attach its sibling. The primary-table shape is owned by
        // SqlSchemaHandler and is not under test here — minimal stub is fine.
        $db->schema()->createTable('topic', [
            'fields' => [
                'id'  => ['type' => 'int', 'not null' => true],
                'uuid' => ['type' => 'varchar', 'length' => 36],
            ],
            'primary key' => ['id'],
        ]);

        $handler->sync($type);

        self::assertTrue($db->schema()->tableExists('topic__translation'));
        self::assertFalse(
            $db->schema()->tableExists('topic__translation__revision'),
            'sync() (single-axis) must not emit a translation-revision sibling',
        );
    }
}
