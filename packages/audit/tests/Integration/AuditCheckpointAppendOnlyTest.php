<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Storage\AppendOnlySchema;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Append-only guard proof for `audit_checkpoint` (WP1).
 *
 * Extends the existing append-only guarantee from `audit_event` to the
 * tamper-evidence checkpoint table: UPDATE, DELETE, and destructive DDL
 * targeting `audit_checkpoint` must throw a `\LogicException` containing
 * "append-only" when routed through `AppendOnlyAuditDatabase`.
 */
#[CoversClass(AppendOnlyAuditDatabase::class)]
#[CoversClass(AppendOnlySchema::class)]
final class AuditCheckpointAppendOnlyTest extends TestCase
{
    private DatabaseInterface $raw;
    private AppendOnlyAuditDatabase $auditDb;

    protected function setUp(): void
    {
        $this->raw = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($this->raw);
        $this->auditDb = new AppendOnlyAuditDatabase($this->raw);
    }

    #[Test]
    public function delete_on_audit_checkpoint_through_audit_db_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->delete('audit_checkpoint');
    }

    #[Test]
    public function update_on_audit_checkpoint_through_audit_db_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->update('audit_checkpoint');
    }

    #[Test]
    public function drop_table_on_audit_checkpoint_through_audit_db_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->schema()->dropTable('audit_checkpoint');
    }

    #[Test]
    public function raw_sql_delete_on_audit_checkpoint_through_audit_db_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        iterator_to_array($this->auditDb->query('DELETE FROM audit_checkpoint WHERE id = 1'));
    }

    #[Test]
    public function raw_sql_update_on_audit_checkpoint_through_audit_db_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        iterator_to_array($this->auditDb->query("UPDATE audit_checkpoint SET signature = 'tampered'"));
    }

    #[Test]
    public function raw_sql_drop_on_audit_checkpoint_through_audit_db_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        iterator_to_array($this->auditDb->query('DROP TABLE audit_checkpoint'));
    }
}
