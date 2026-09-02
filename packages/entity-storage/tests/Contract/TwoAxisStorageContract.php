<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Contract;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Schema\RevisionTableBuilder;
use Waaseyaa\Field\FieldDefinition;

/**
 * Abstract contract for two-axis (revisionable + translatable) storage
 * schema emitters (M-004 / WP01 / WP02).
 *
 * Concrete subclasses bind the contract to a primary backend id (`sql-column`
 * or `sql-blob`) by overriding {@see primaryBackendId()}. Invariants asserted
 * here apply equally to both backends per spec §3.1 (FR-001..FR-008).
 *
 * Marked `@CoversNothing` because contract tests verify behavior surfaces, not
 * a specific class — coverage is attributed to concrete subclasses.
 *
 * @api
 */
#[CoversNothing]
abstract class TwoAxisStorageContract extends TestCase
{
    /**
     * The primary backend id this contract subclass targets.
     * `'sql-column'` for WP01, `'sql-blob'` for WP02.
     */
    abstract protected function primaryBackendId(): string;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function makeSqliteDatabase(): DBALDatabase
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        return new DBALDatabase($connection);
    }

    protected function makeRevisionTableBuilder(DBALDatabase $database): RevisionTableBuilder
    {
        return new RevisionTableBuilder($database);
    }

    /**
     * Build a two-axis (revisionable + translatable) entity type.
     */
    protected function makeTwoAxisEntityType(string $id = 'teaching'): EntityType
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
            primaryStorageBackend: $this->primaryBackendId(),
        );
    }

    /**
     * @return list<string> Column names of the table.
     */
    protected function columnsOf(DBALDatabase $database, string $table): array
    {
        $cols = [];
        $rows = iterator_to_array($database->query('PRAGMA table_info(' . $table . ')'));
        foreach ($rows as $row) {
            $cols[] = (string) $row['name'];
        }
        return $cols;
    }

    /**
     * Index names declared on a SQLite table (via PRAGMA index_list).
     *
     * @return list<string>
     */
    protected function indexesOf(DBALDatabase $database, string $table): array
    {
        $names = [];
        $rows = iterator_to_array($database->query('PRAGMA index_list(' . $table . ')'));
        foreach ($rows as $row) {
            $names[] = (string) $row['name'];
        }
        return $names;
    }

    // -------------------------------------------------------------------------
    // Contract — schema invariants
    // -------------------------------------------------------------------------

    #[Test]
    public function two_axis_emits_both_revision_and_translation_revision_tables(): void
    {
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);
        $type = $this->makeTwoAxisEntityType('teaching');

        $fields = [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'body', type: 'text', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
        ];

        $builder->buildTwoAxis($type, $this->primaryBackendId(), $fields);

        self::assertTrue($db->schema()->tableExists('teaching__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__translation__revision'));
    }

    #[Test]
    public function translation_revision_table_carries_entity_id_langcode_and_vid(): void
    {
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);
        $type = $this->makeTwoAxisEntityType('teaching');

        $builder->buildTwoAxis($type, $this->primaryBackendId(), []);

        $columns = $this->columnsOf($db, 'teaching__translation__revision');
        self::assertContains('vid', $columns, 'surrogate vid for cheap loadRevision()');
        self::assertContains('entity_id', $columns, 'composite identity row');
        self::assertContains('langcode', $columns, 'composite identity row');
    }

    #[Test]
    public function non_translatable_fields_live_only_on_revision_table(): void
    {
        // FR-004 — non-translatable fields stored once on the default-langcode
        // revision; never duplicated to per-langcode revision rows.
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);
        $type = $this->makeTwoAxisEntityType('teaching');

        $fields = [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
            new FieldDefinition(name: 'priority', type: 'integer'),
        ];

        $builder->buildTwoAxis($type, $this->primaryBackendId(), $fields);

        if ($this->primaryBackendId() === 'sql-column') {
            $revCols = $this->columnsOf($db, 'teaching__revision');
            $transCols = $this->columnsOf($db, 'teaching__translation__revision');

            self::assertContains('author_id', $revCols, 'non-translatable on __revision');
            self::assertContains('priority', $revCols);
            self::assertNotContains('author_id', $transCols, 'never duplicated to __translation__revision');
            self::assertNotContains('priority', $transCols);
        } else {
            // sql-blob: a single _data blob per row; non-translatable presence
            // is encoded in the writer (out of scope for the schema emitter).
            $revCols = $this->columnsOf($db, 'teaching__revision');
            self::assertContains('_data', $revCols);
        }
    }

    #[Test]
    public function translatable_fields_live_on_translation_revision_table(): void
    {
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);
        $type = $this->makeTwoAxisEntityType('teaching');

        $fields = [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'body', type: 'text', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
        ];

        $builder->buildTwoAxis($type, $this->primaryBackendId(), $fields);

        if ($this->primaryBackendId() === 'sql-column') {
            $transCols = $this->columnsOf($db, 'teaching__translation__revision');
            $revCols = $this->columnsOf($db, 'teaching__revision');

            self::assertContains('title', $transCols);
            self::assertContains('body', $transCols);
            self::assertNotContains('title', $revCols, 'translatable not duplicated to __revision');
            self::assertNotContains('body', $revCols);
        } else {
            $transCols = $this->columnsOf($db, 'teaching__translation__revision');
            self::assertContains('_data', $transCols);
        }
    }

    #[Test]
    public function vector_routed_translatable_field_is_rejected(): void
    {
        // FR-006 — translatable fields cannot be routed to non-sql backends.
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);
        $type = $this->makeTwoAxisEntityType('teaching');

        $fields = [
            (new FieldDefinition(name: 'embedding', type: 'text', translatable: true))
                ->storedIn('vector'),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupportedTwoAxisField');

        $builder->buildTwoAxis($type, $this->primaryBackendId(), $fields);
    }

    #[Test]
    public function single_axis_revisionable_only_is_unchanged_by_two_axis_support(): void
    {
        // Regression gate per spec §12.3 R-A: M-006 single-axis output is byte-
        // for-byte unchanged. Single-axis types continue to call build().
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);

        $type = new EntityType(
            id: 'post',
            label: 'Post',
            class: \stdClass::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'revision' => 'vid'],
            revisionable: true,
            translatable: false,
        );

        $builder->build($type, $this->primaryBackendId(), [
            new FieldDefinition(name: 'title', type: 'string'),
        ]);

        self::assertTrue($db->schema()->tableExists('post__revision'));
        // Single-axis emits NO translation-revision sibling.
        self::assertFalse(
            $db->schema()->tableExists('post__translation__revision'),
            'single-axis revisionable types must not get a translation-revision table',
        );

        $cols = $this->columnsOf($db, 'post__revision');
        self::assertContains('vid', $cols);
        self::assertContains('pid', $cols);
        // No langcode column on single-axis revision tables.
        self::assertNotContains('langcode', $cols);
    }

    #[Test]
    public function build_two_axis_rejects_non_revisionable_entity_type(): void
    {
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);

        $type = new EntityType(
            id: 'taxonomy_term',
            label: 'Term',
            class: ContentEntityBase::class,
            keys: [
                'id'               => 'tid',
                'langcode'         => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            primaryStorageBackend: $this->primaryBackendId(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $builder->buildTwoAxis($type, $this->primaryBackendId(), []);
    }

    #[Test]
    public function build_two_axis_rejects_non_translatable_entity_type(): void
    {
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);

        $type = new EntityType(
            id: 'release',
            label: 'Release',
            class: \stdClass::class,
            keys: ['id' => 'rid', 'revision' => 'vid'],
            revisionable: true,
            translatable: false,
            primaryStorageBackend: $this->primaryBackendId(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $builder->buildTwoAxis($type, $this->primaryBackendId(), []);
    }

    #[Test]
    public function build_two_axis_is_idempotent(): void
    {
        $db = $this->makeSqliteDatabase();
        $builder = $this->makeRevisionTableBuilder($db);
        $type = $this->makeTwoAxisEntityType('teaching');

        $fields = [new FieldDefinition(name: 'title', type: 'string', translatable: true)];

        $builder->buildTwoAxis($type, $this->primaryBackendId(), $fields);
        // Second call must not throw.
        $builder->buildTwoAxis($type, $this->primaryBackendId(), $fields);

        self::assertTrue($db->schema()->tableExists('teaching__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__translation__revision'));
    }
}
