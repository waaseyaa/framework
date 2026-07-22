<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class NodeAuthoringTimestampMigrationTest extends TestCase
{
    #[Test]
    public function backfillsOnlyMissingTimestampsInMigratedBlobRowsAndIsIdempotent(): void
    {
        $db = DBALDatabase::createSqlite();
        $connection = $db->getConnection();
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE node (
                nid INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) NOT NULL,
                type VARCHAR(128) NOT NULL,
                title VARCHAR(255) NOT NULL,
                langcode VARCHAR(12) NOT NULL DEFAULT 'en',
                revision_id INTEGER,
                published_revision_id INTEGER,
                _data TEXT NOT NULL DEFAULT '{}'
            )
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE node_revision (
                entity_id VARCHAR(128) NOT NULL,
                revision_id INTEGER NOT NULL,
                revision_created VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                type VARCHAR(128) NOT NULL DEFAULT '',
                langcode VARCHAR(12) NOT NULL DEFAULT 'en',
                uuid VARCHAR(128) NOT NULL DEFAULT '',
                _data TEXT NOT NULL DEFAULT '{}',
                PRIMARY KEY (entity_id, revision_id)
            )
            SQL);
        $connection->insert('node', [
            'uuid' => 'missing',
            'type' => 'post',
            'title' => 'Browser-created post',
            '_data' => json_encode(['slug' => 'browser-created-post'], JSON_THROW_ON_ERROR),
        ]);
        $connection->insert('node', [
            'uuid' => 'complete',
            'type' => 'post',
            'title' => 'Imported post',
            '_data' => json_encode(['created' => 1_600_000_000, 'changed' => 1_600_000_100], JSON_THROW_ON_ERROR),
        ]);
        $connection->insert('node', [
            'uuid' => 'partial',
            'type' => 'post',
            'title' => 'Partially timestamped post',
            '_data' => json_encode(['changed' => 1_500_000_000], JSON_THROW_ON_ERROR),
        ]);
        $connection->insert('node_revision', [
            'entity_id' => '1',
            'revision_id' => 1,
            'revision_created' => '2026-07-01T00:00:00+00:00',
            'title' => 'Browser-created post',
            'type' => 'post',
            'uuid' => 'missing',
            '_data' => json_encode(['slug' => 'browser-created-post'], JSON_THROW_ON_ERROR),
        ]);

        $path = dirname(__DIR__, 3) . '/migrations/2026_07_22_000002_node_authoring_timestamps.php';
        self::assertFileExists($path);
        $migration = require $path;
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up(new SchemaBuilder($connection));
        $firstBackfill = json_decode((string) $connection->fetchOne("SELECT _data FROM node WHERE uuid = 'missing'"), true, flags: JSON_THROW_ON_ERROR);
        $firstRevisionBackfill = json_decode((string) $connection->fetchOne("SELECT _data FROM node_revision WHERE entity_id = '1' AND revision_id = 1"), true, flags: JSON_THROW_ON_ERROR);
        $migration->up(new SchemaBuilder($connection));
        $secondBackfill = json_decode((string) $connection->fetchOne("SELECT _data FROM node WHERE uuid = 'missing'"), true, flags: JSON_THROW_ON_ERROR);
        $secondRevisionBackfill = json_decode((string) $connection->fetchOne("SELECT _data FROM node_revision WHERE entity_id = '1' AND revision_id = 1"), true, flags: JSON_THROW_ON_ERROR);
        $complete = json_decode((string) $connection->fetchOne("SELECT _data FROM node WHERE uuid = 'complete'"), true, flags: JSON_THROW_ON_ERROR);
        $partial = json_decode((string) $connection->fetchOne("SELECT _data FROM node WHERE uuid = 'partial'"), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsInt($firstBackfill['created']);
        self::assertSame($firstBackfill['created'], $firstBackfill['changed']);
        self::assertSame($firstBackfill, $secondBackfill, 'A rerun must not restamp already repaired rows.');
        self::assertSame($firstRevisionBackfill, $secondRevisionBackfill, 'Revision snapshots must be repaired idempotently too.');
        self::assertSame($firstBackfill['created'], $firstRevisionBackfill['created']);
        self::assertSame($firstRevisionBackfill['created'], $firstRevisionBackfill['changed']);
        self::assertSame(['created' => 1_600_000_000, 'changed' => 1_600_000_100], $complete);
        self::assertSame(
            ['changed' => 1_500_000_000, 'created' => 1_500_000_000],
            $partial,
            'A partial legacy row must inherit its sibling timestamp instead of appearing newly authored at deploy time.',
        );
    }
}
