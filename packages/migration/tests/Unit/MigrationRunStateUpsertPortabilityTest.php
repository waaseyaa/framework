<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Migration\MigrationRunState;

/**
 * Regression guard for D-37: the per-record upsert must use a portable
 * lookup-then-update-or-insert strategy (the same one MigrationIdMap uses)
 * rather than SQLite/Postgres-only `INSERT ... ON CONFLICT ... DO UPDATE`,
 * because MigrationRunStateSchema advertises DDL portable to MySQL too.
 */
#[CoversClass(MigrationRunState::class)]
final class MigrationRunStateUpsertPortabilityTest extends TestCase
{
    private DBALDatabase $db;
    private MigrationRunState $runState;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        $migrationFile = \dirname(__DIR__, 2)
            . '/migrations/2026_05_13_000002_create_migration_run_state.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->db->getConnection());
        $migration->up($schema);

        $this->runState = new MigrationRunState($this->db);
    }

    #[Test]
    public function write_path_uses_no_dialect_specific_upsert_syntax(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(MigrationRunState::class))->getFileName(),
        );

        // Strip docblocks/comments so a historical mention in prose does not
        // trip the guard; we only care about executable SQL.
        $codeOnly = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        $codeOnly = (string) preg_replace('~//[^\n]*~', '', $codeOnly);

        self::assertStringNotContainsStringIgnoringCase(
            'ON CONFLICT',
            $codeOnly,
            'MigrationRunState upsert must not emit ON CONFLICT (SQLite/Postgres only); '
            . 'use the portable update()/insert() builder path (D-37).',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'ON DUPLICATE KEY',
            $codeOnly,
            'MigrationRunState upsert must not branch on MySQL-only syntax either (D-37).',
        );
        self::assertStringNotContainsString(
            'excluded.',
            $codeOnly,
            'The `excluded.` pseudo-table is ON CONFLICT syntax; the portable path '
            . 'does not use it (D-37).',
        );
    }

    #[Test]
    public function insert_then_overwrite_keeps_latest_outcome(): void
    {
        // First write -> INSERT branch.
        $this->runState->recordError('m1', 'hash-a', 'run-1', 3, 'BOOM', 'first');

        $row = $this->runState->lookupItem('m1', 'hash-a');
        self::assertNotNull($row);
        self::assertSame('error', $row['item_status']);
        self::assertSame('run-1', $row['run_id']);
        self::assertSame(3, $row['position']);
        self::assertSame('BOOM', $row['error_code']);

        // Second write for the same composite key -> UPDATE branch; latest wins,
        // error fields cleared back to null.
        $this->runState->recordSuccess('m1', 'hash-a', 'run-2', 9);

        $row = $this->runState->lookupItem('m1', 'hash-a');
        self::assertNotNull($row);
        self::assertSame('success', $row['item_status']);
        self::assertSame('run-2', $row['run_id']);
        self::assertSame(9, $row['position']);
        self::assertNull($row['error_code']);
        self::assertNull($row['error_message']);

        // Still exactly one row for the key (no duplicate from the insert path).
        self::assertSame(
            ['success' => 1, 'error' => 0, 'skipped' => 0],
            $this->runState->countByStatus('m1'),
        );
    }
}
