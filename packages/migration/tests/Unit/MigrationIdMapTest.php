<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;

#[CoversClass(MigrationIdMap::class)]
#[CoversClass(MigrationIdMapSchema::class)]
final class MigrationIdMapTest extends TestCase
{
    private DBALDatabase $db;
    private MigrationIdMap $idMap;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        // Apply the schema via the package migration file so the test
        // exercises the same DDL that ships in production.
        $migrationFile = \dirname(__DIR__, 2) . '/migrations/2026_05_13_000001_create_migration_id_map.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->db->getConnection());
        $migration->up($schema);

        $this->idMap = new MigrationIdMap($this->db);
    }

    #[Test]
    public function table_and_indexes_exist_after_migration(): void
    {
        $schema = new SchemaBuilder($this->db->getConnection());
        self::assertTrue($schema->hasTable('migration_id_map'));

        // Inspect via SQLite's pragma to assert both the PK and the entity index.
        $rows = $this->db->query("PRAGMA index_list('migration_id_map')");
        $names = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            $names[] = (string) $row['name'];
        }
        self::assertContains('migration_id_map__entity', $names);
    }

    #[Test]
    public function lookup_returns_null_for_unknown_pair(): void
    {
        $result = $this->idMap->lookupDestination('m1', new SourceId('wp', ['id' => 1]));

        self::assertNull($result);
    }

    #[Test]
    public function upsert_inserts_a_new_row_then_lookup_returns_it(): void
    {
        $sourceId = new SourceId('wordpress_post', ['post_id' => 42]);

        $written = $this->idMap->upsert(
            migrationId: 'wp_posts_to_teachings',
            sourceId: $sourceId,
            destinationEntityType: 'node',
            destinationUuid: '01J0000000000000000000ABCD',
            sourceRecordHash: 'hash-v1',
            runId: '01J0000000000000000000RUN1',
            now: new \DateTimeImmutable('2026-05-13T10:00:00Z'),
        );

        self::assertSame('node', $written->destinationEntityType);
        self::assertSame('01J0000000000000000000ABCD', $written->destinationUuid);

        $looked = $this->idMap->lookupDestination('wp_posts_to_teachings', $sourceId);
        self::assertNotNull($looked);
        self::assertSame('node', $looked->destinationEntityType);
        self::assertSame('01J0000000000000000000ABCD', $looked->destinationUuid);
        self::assertSame('hash-v1', $looked->sourceRecordHash);
        self::assertSame('01J0000000000000000000RUN1', $looked->runId);
        self::assertSame('2026-05-13T10:00:00Z', $looked->writtenAt);
    }

    #[Test]
    public function upsert_with_same_pk_updates_existing_row_no_duplicates(): void
    {
        $sourceId = new SourceId('wordpress_post', ['post_id' => 42]);

        $this->idMap->upsert(
            migrationId: 'm1',
            sourceId: $sourceId,
            destinationEntityType: 'node',
            destinationUuid: 'uuid-1',
            sourceRecordHash: 'hash-v1',
            runId: 'run-1',
            now: new \DateTimeImmutable('2026-05-13T10:00:00Z'),
        );

        $this->idMap->upsert(
            migrationId: 'm1',
            sourceId: $sourceId,
            destinationEntityType: 'node',
            destinationUuid: 'uuid-1',
            sourceRecordHash: 'hash-v2',
            runId: 'run-2',
            now: new \DateTimeImmutable('2026-05-13T11:00:00Z'),
        );

        self::assertSame(1, $this->idMap->countForMigration('m1'));

        $looked = $this->idMap->lookupDestination('m1', $sourceId);
        self::assertNotNull($looked);
        self::assertSame('hash-v2', $looked->sourceRecordHash);
        self::assertSame('run-2', $looked->runId);
        self::assertSame('2026-05-13T11:00:00Z', $looked->writtenAt);
    }

    #[Test]
    public function delete_removes_only_targeted_row(): void
    {
        $a = new SourceId('wp', ['id' => 1]);
        $b = new SourceId('wp', ['id' => 2]);

        $this->idMap->upsert('m1', $a, 'node', 'u1', 'h1', 'r1');
        $this->idMap->upsert('m1', $b, 'node', 'u2', 'h2', 'r2');

        $removed = $this->idMap->delete('m1', $a);

        self::assertTrue($removed);
        self::assertNull($this->idMap->lookupDestination('m1', $a));
        self::assertNotNull($this->idMap->lookupDestination('m1', $b));
        self::assertSame(1, $this->idMap->countForMigration('m1'));
    }

    #[Test]
    public function delete_unknown_row_returns_false(): void
    {
        $removed = $this->idMap->delete('m1', new SourceId('wp', ['id' => 99]));

        self::assertFalse($removed);
    }

    #[Test]
    public function delete_all_for_migration_is_scoped(): void
    {
        $a = new SourceId('wp', ['id' => 1]);
        $b = new SourceId('wp', ['id' => 2]);

        $this->idMap->upsert('m1', $a, 'node', 'u1', 'h1', 'r1');
        $this->idMap->upsert('m1', $b, 'node', 'u2', 'h2', 'r2');
        $this->idMap->upsert('m2', $a, 'node', 'u3', 'h3', 'r3');

        $deleted = $this->idMap->deleteAllForMigration('m1');

        self::assertSame(2, $deleted);
        self::assertSame(0, $this->idMap->countForMigration('m1'));
        self::assertSame(1, $this->idMap->countForMigration('m2'));
        self::assertNotNull($this->idMap->lookupDestination('m2', $a));
    }

    #[Test]
    public function delete_all_for_migration_returns_zero_when_empty(): void
    {
        self::assertSame(0, $this->idMap->deleteAllForMigration('nonexistent-migration'));
    }

    #[Test]
    public function walk_reverse_creation_yields_in_descending_imported_order(): void
    {
        $earlier = new SourceId('wp', ['id' => 1]);
        $middle = new SourceId('wp', ['id' => 2]);
        $latest = new SourceId('wp', ['id' => 3]);

        $this->idMap->upsert('m1', $earlier, 'node', 'u1', 'h1', 'r1', new \DateTimeImmutable('2026-05-13T10:00:00Z'));
        $this->idMap->upsert('m1', $latest, 'node', 'u3', 'h3', 'r3', new \DateTimeImmutable('2026-05-13T12:00:00Z'));
        $this->idMap->upsert('m1', $middle, 'node', 'u2', 'h2', 'r2', new \DateTimeImmutable('2026-05-13T11:00:00Z'));

        $uuids = [];
        foreach ($this->idMap->walkReverseCreation('m1') as $result) {
            $uuids[] = $result->destinationUuid;
        }

        self::assertSame(['u3', 'u2', 'u1'], $uuids);
    }

    #[Test]
    public function walk_reverse_creation_breaks_ties_by_run_id_descending(): void
    {
        $a = new SourceId('wp', ['id' => 1]);
        $b = new SourceId('wp', ['id' => 2]);

        $tiedTime = new \DateTimeImmutable('2026-05-13T10:00:00Z');
        $this->idMap->upsert('m1', $a, 'node', 'u-a', 'ha', 'run-alpha', $tiedTime);
        $this->idMap->upsert('m1', $b, 'node', 'u-b', 'hb', 'run-bravo', $tiedTime);

        $runIds = [];
        foreach ($this->idMap->walkReverseCreation('m1') as $result) {
            $runIds[] = $result->runId;
        }

        // Same timestamp; secondary sort run_id DESC -> bravo before alpha.
        self::assertSame(['run-bravo', 'run-alpha'], $runIds);
    }

    #[Test]
    public function walk_reverse_creation_is_a_generator(): void
    {
        $generator = $this->idMap->walkReverseCreation('m1');

        self::assertInstanceOf(\Generator::class, $generator);
        // Drain to satisfy generator semantics.
        foreach ($generator as $_) {
            // intentionally empty
        }
    }

    #[Test]
    public function lookup_destination_across_returns_first_matching_partition(): void
    {
        $sourceId = new SourceId('media', ['id' => '10']);
        $this->idMap->upsert('media_document', $sourceId, 'media', 'document-uuid', 'hash', 'run');
        $this->idMap->upsert('media_image', $sourceId, 'media', 'image-uuid', 'hash', 'run');

        $result = $this->idMap->lookupDestinationAcross(['media_image', 'media_document'], $sourceId);

        self::assertNotNull($result);
        self::assertSame('image-uuid', $result->destinationUuid);
        self::assertNull($this->idMap->lookupDestinationAcross(['missing'], $sourceId));
    }

    #[Test]
    public function lookup_destination_across_rejects_empty_partition_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->idMap->lookupDestinationAcross([''], new SourceId('media', ['id' => '10']));
    }

    #[Test]
    public function transactional_commits_on_success(): void
    {
        $sourceId = new SourceId('wp', ['id' => 1]);

        $this->idMap->transactional(function () use ($sourceId): void {
            $this->idMap->upsert('m1', $sourceId, 'node', 'uuid-1', 'hash-1', 'run-1');
        });

        self::assertNotNull($this->idMap->lookupDestination('m1', $sourceId));
    }

    #[Test]
    public function transactional_rolls_back_on_exception(): void
    {
        $sourceId = new SourceId('wp', ['id' => 1]);

        try {
            $this->idMap->transactional(function () use ($sourceId): void {
                $this->idMap->upsert('m1', $sourceId, 'node', 'uuid-1', 'hash-1', 'run-1');
                throw new \RuntimeException('forced rollback');
            });
            self::fail('Expected RuntimeException to surface from transactional()');
        } catch (\RuntimeException $e) {
            self::assertSame('forced rollback', $e->getMessage());
        }

        self::assertSame(0, $this->idMap->countForMigration('m1'));
    }

    #[Test]
    public function count_returns_zero_for_unknown_migration(): void
    {
        self::assertSame(0, $this->idMap->countForMigration('never-ran'));
    }

    #[Test]
    public function upsert_rejects_empty_required_strings(): void
    {
        $sourceId = new SourceId('wp', ['id' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->idMap->upsert('', $sourceId, 'node', 'u', 'h', 'r');
    }

    #[Test]
    public function down_drops_the_table(): void
    {
        $migrationFile = \dirname(__DIR__, 2) . '/migrations/2026_05_13_000001_create_migration_id_map.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->db->getConnection());
        self::assertTrue($schema->hasTable('migration_id_map'));

        $migration->down($schema);

        $schemaAfter = new SchemaBuilder($this->db->getConnection());
        self::assertFalse($schemaAfter->hasTable('migration_id_map'));
    }

    #[Test]
    public function up_down_up_is_reversible(): void
    {
        $migrationFile = \dirname(__DIR__, 2) . '/migrations/2026_05_13_000001_create_migration_id_map.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->db->getConnection());

        // setUp() already ran up(); roll back and re-apply.
        $migration->down($schema);
        $migration->up($schema);

        $schemaAfter = new SchemaBuilder($this->db->getConnection());
        self::assertTrue($schemaAfter->hasTable('migration_id_map'));
    }
}
