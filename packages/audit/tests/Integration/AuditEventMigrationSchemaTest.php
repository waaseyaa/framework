<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Proves that the 000001 migration produces schema identical to the runtime
 * AuditEventSchemaHandler boot path (L1-audit.md m1 fix).
 *
 * A standalone `migrate` used to produce a stale CREATE TABLE lacking
 * actor_uid, row_hash, and prev_hash — columns the AuditEventWriter always
 * inserts. This test guards against regression to that two-source-of-truth
 * state by running the migration in isolation and asserting all writer-required
 * columns are present.
 *
 * Uses #[CoversNothing] because the subject under test is a migration file,
 * not a class.
 */
#[CoversNothing]
final class AuditEventMigrationSchemaTest extends TestCase
{
    #[Test]
    public function migration_creates_actor_uid_column(): void
    {
        $db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($db->getConnection());

        $migration = require dirname(__DIR__, 2) . '/migrations/2026_05_25_000001_create_audit_event_table.php';
        $migration->up($schema);

        $this->assertTrue(
            $db->schema()->fieldExists('audit_event', 'actor_uid'),
            'migration must create actor_uid (the column the stale migration lacked)',
        );
    }

    #[Test]
    public function migration_creates_row_hash_column(): void
    {
        $db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($db->getConnection());

        $migration = require dirname(__DIR__, 2) . '/migrations/2026_05_25_000001_create_audit_event_table.php';
        $migration->up($schema);

        $this->assertTrue(
            $db->schema()->fieldExists('audit_event', 'row_hash'),
            'migration must create row_hash (tamper-evidence chain column)',
        );
    }

    #[Test]
    public function migration_creates_prev_hash_column(): void
    {
        $db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($db->getConnection());

        $migration = require dirname(__DIR__, 2) . '/migrations/2026_05_25_000001_create_audit_event_table.php';
        $migration->up($schema);

        $this->assertTrue(
            $db->schema()->fieldExists('audit_event', 'prev_hash'),
            'migration must create prev_hash (tamper-evidence chain column)',
        );
    }

    #[Test]
    public function migration_creates_core_audit_event_columns(): void
    {
        $db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($db->getConnection());

        $migration = require dirname(__DIR__, 2) . '/migrations/2026_05_25_000001_create_audit_event_table.php';
        $migration->up($schema);

        foreach (['account_uid', 'event_kind', 'created_at'] as $col) {
            $this->assertTrue(
                $db->schema()->fieldExists('audit_event', $col),
                sprintf('migration must create sanity column "%s"', $col),
            );
        }
    }

    #[Test]
    public function migration_also_creates_audit_checkpoint_table(): void
    {
        $db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($db->getConnection());

        $migration = require dirname(__DIR__, 2) . '/migrations/2026_05_25_000001_create_audit_event_table.php';
        $migration->up($schema);

        $this->assertTrue(
            $db->schema()->tableExists('audit_checkpoint'),
            'migration must create audit_checkpoint table (proves full delegation to AuditEventSchemaHandler)',
        );
    }
}
