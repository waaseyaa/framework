<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase29;

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
 * Integration: emit both single-axis and two-axis revision schemas against
 * the same in-memory SQLite database and verify they coexist without naming
 * collisions and that data round-trips with the expected FR semantics.
 *
 * Phase 29 corresponds to M-004 — Entity Storage Translatable Revisions.
 */
#[CoversNothing]
final class TwoAxisSchemaIntegrationTest extends TestCase
{
    private function makeSqlite(): DBALDatabase
    {
        return new DBALDatabase(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        );
    }

    private function twoAxisType(string $id): EntityType
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

    #[Test]
    public function two_axis_and_single_axis_revision_tables_coexist(): void
    {
        $db = $this->makeSqlite();
        $builder = new RevisionTableBuilder($db);

        // Single-axis revisionable-only.
        $blog = new EntityType(
            id: 'blog',
            label: 'Blog',
            class: \stdClass::class,
            keys: ['id' => 'bid', 'uuid' => 'uuid', 'revision' => 'vid'],
            revisionable: true,
        );
        $builder->build($blog, 'sql-column', [
            new FieldDefinition(name: 'title', type: 'string'),
        ]);

        // Two-axis revisionable + translatable.
        $teaching = $this->twoAxisType('teaching');
        $builder->buildTwoAxis($teaching, 'sql-column', [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'body', type: 'text', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
        ]);

        // Coexistence: both single-axis and two-axis tables exist.
        self::assertTrue($db->schema()->tableExists('blog__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__revision'));
        self::assertTrue($db->schema()->tableExists('teaching__translation__revision'));

        // No accidental cross-contamination.
        self::assertFalse(
            $db->schema()->tableExists('blog__translation__revision'),
            'single-axis types must not produce a translation-revision sibling',
        );
    }

    #[Test]
    public function two_axis_schema_supports_round_trip_writes_per_logical_pk(): void
    {
        $db = $this->makeSqlite();
        $builder = new RevisionTableBuilder($db);
        $teaching = $this->twoAxisType('teaching');

        $builder->buildTwoAxis($teaching, 'sql-column', [
            new FieldDefinition(name: 'title', type: 'string', translatable: true),
            new FieldDefinition(name: 'body', type: 'text', translatable: true),
            new FieldDefinition(name: 'author_id', type: 'integer'),
        ]);

        $connection = $db->getConnection();

        // Insert one English revision (default-langcode) and one Anishinaabemowin
        // revision for the same entity.
        $connection->executeStatement(
            'INSERT INTO teaching__translation__revision (entity_id, langcode, title, body) VALUES (?, ?, ?, ?)',
            ['42', 'en', 'How fire learned', 'Once upon a time...'],
        );
        $connection->executeStatement(
            'INSERT INTO teaching__translation__revision (entity_id, langcode, title, body) VALUES (?, ?, ?, ?)',
            ['42', 'oj', 'Ishkode gikinoo\'amaagewin', 'Gete-aya\'aag...'],
        );
        // Non-translatable values stored once on the default-langcode __revision row.
        $connection->executeStatement(
            'INSERT INTO teaching__revision (tid, author_id) VALUES (?, ?)',
            ['42', 7],
        );

        // FR-001: composite uniqueness — same (entity_id, langcode, vid) collides.
        // Read back current English revision: vid must be assigned by SERIAL.
        $row = $connection->fetchAssociative(
            "SELECT vid, title FROM teaching__translation__revision WHERE entity_id = '42' AND langcode = 'en'",
        );
        self::assertIsArray($row);
        self::assertSame('How fire learned', $row['title']);
        self::assertGreaterThan(0, (int) $row['vid']);

        // FR-004: author_id (non-translatable) is NOT present on translation-revision.
        $columns = [];
        $cursor = $db->query('PRAGMA table_info(teaching__translation__revision)');
        foreach (iterator_to_array($cursor) as $colRow) {
            $columns[] = (string) $colRow['name'];
        }
        self::assertNotContains('author_id', $columns);

        // FR-008 scaffold: the non-translatable row exists with the entity id +
        // its own vid. Pointer reconciliation across __revision and
        // __translation__revision is owned by WP04 storage driver.
        $authorRow = $connection->fetchAssociative(
            "SELECT vid, author_id FROM teaching__revision WHERE tid = '42'",
        );
        self::assertIsArray($authorRow);
        self::assertSame(7, (int) $authorRow['author_id']);
    }
}
