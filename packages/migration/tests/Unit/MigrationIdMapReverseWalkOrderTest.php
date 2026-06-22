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
use Waaseyaa\Migration\SourceId;

/**
 * Drift guard for FR-043: the rollback walk orders by *last-imported* time,
 * NOT by creation order.
 *
 * The stable-surface `migration_id_map` table (FR-025) has no immutable
 * creation/sequence column; the only ordering signal is `last_imported_at`,
 * which `upsert()` refreshes on every re-import. These tests pin that the
 * walk honours reverse last-imported order (tie-broken by `last_run_id`),
 * and in particular that a row created FIRST but re-imported LAST resorts to
 * the front of the walk — the exact case that distinguishes last-imported
 * order from creation order, and which the existing MigrationIdMapTest walk
 * cases never construct (they only ever INSERT distinct SourceIds).
 *
 * If a future change reintroduces a literal "reverse-creation order" claim
 * (e.g. by adding a creation column and ordering on it) this suite fails,
 * forcing the contract and the implementation back into agreement.
 */
#[CoversClass(MigrationIdMap::class)]
final class MigrationIdMapReverseWalkOrderTest extends TestCase
{
    private DBALDatabase $db;
    private MigrationIdMap $idMap;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        // Apply the real shipped DDL via the package migration file so the
        // test exercises the production schema, not a hand-built table.
        $migrationFile = \dirname(__DIR__, 2) . '/migrations/2026_05_13_000001_create_migration_id_map.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->db->getConnection());
        $migration->up($schema);

        $this->idMap = new MigrationIdMap($this->db);
    }

    #[Test]
    public function walk_orders_by_last_imported_not_creation(): void
    {
        $first = new SourceId('wp', ['id' => 1]);
        $second = new SourceId('wp', ['id' => 2]);
        $third = new SourceId('wp', ['id' => 3]);

        // Create rows in order u1, u2, u3 (ascending import time).
        $this->idMap->upsert('m1', $first, 'node', 'u1', 'h1', 'r1', new \DateTimeImmutable('2026-05-13T10:00:00Z'));
        $this->idMap->upsert('m1', $second, 'node', 'u2', 'h2', 'r2', new \DateTimeImmutable('2026-05-13T11:00:00Z'));
        $this->idMap->upsert('m1', $third, 'node', 'u3', 'h3', 'r3', new \DateTimeImmutable('2026-05-13T12:00:00Z'));

        // Re-import the EARLIEST-CREATED row (u1) with a newer timestamp —
        // a changed record on a later run. upsert() UPDATEs in place and
        // refreshes last_imported_at; destination_uuid is unchanged.
        $this->idMap->upsert('m1', $first, 'node', 'u1', 'h1-changed', 'r4', new \DateTimeImmutable('2026-05-13T13:00:00Z'));

        $uuids = [];
        foreach ($this->idMap->walkReverseCreation('m1') as $result) {
            $uuids[] = $result->destinationUuid;
        }

        // Reverse last-imported order: u1 (13:00) now walks FIRST, then
        // u3 (12:00), then u2 (11:00). A reverse-CREATION order would yield
        // ['u3', 'u2', 'u1'] — this assertion fails against any such claim.
        self::assertSame(['u1', 'u3', 'u2'], $uuids);
    }

    #[Test]
    public function walk_with_keys_orders_by_last_imported_not_creation(): void
    {
        $first = new SourceId('wp', ['id' => 1]);
        $second = new SourceId('wp', ['id' => 2]);

        $this->idMap->upsert('m1', $first, 'node', 'u1', 'h1', 'r1', new \DateTimeImmutable('2026-05-13T10:00:00Z'));
        $this->idMap->upsert('m1', $second, 'node', 'u2', 'h2', 'r2', new \DateTimeImmutable('2026-05-13T11:00:00Z'));

        // Re-import the first-created row last.
        $this->idMap->upsert('m1', $first, 'node', 'u1', 'h1-changed', 'r3', new \DateTimeImmutable('2026-05-13T12:00:00Z'));

        $uuids = [];
        $hashes = [];
        foreach ($this->idMap->walkReverseCreationWithKeys('m1') as [$sourceIdHash, $writeResult]) {
            $uuids[] = $writeResult->destinationUuid;
            $hashes[] = $sourceIdHash;
        }

        self::assertSame(['u1', 'u2'], $uuids);
        // The key emitted alongside u1 is the first SourceId's hash, so the
        // rollback walker can delete the correct id-map row after rollback.
        self::assertSame($first->hash(), $hashes[0]);
        self::assertSame($second->hash(), $hashes[1]);
    }

    #[Test]
    public function walk_breaks_ties_by_last_run_id_descending(): void
    {
        $a = new SourceId('wp', ['id' => 1]);
        $b = new SourceId('wp', ['id' => 2]);

        // Identical timestamp forces the secondary last_run_id DESC sort.
        $tied = new \DateTimeImmutable('2026-05-13T10:00:00Z');
        $this->idMap->upsert('m1', $a, 'node', 'u-a', 'ha', 'run-alpha', $tied);
        $this->idMap->upsert('m1', $b, 'node', 'u-b', 'hb', 'run-bravo', $tied);

        $runIds = [];
        foreach ($this->idMap->walkReverseCreation('m1') as $result) {
            $runIds[] = $result->runId;
        }

        // Sub-second determinism (R2 mitigation): run-bravo before run-alpha.
        self::assertSame(['run-bravo', 'run-alpha'], $runIds);
    }
}
