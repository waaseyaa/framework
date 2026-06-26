<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Storage\AppendOnlySchema;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * OCAP append-only proof (FR-003): once an audit event is written, it cannot be
 * updated or deleted through any supported audit path.
 *
 * "Supported audit path" = the AppendOnlyAuditDatabase the writer and audit
 * subsystem are wired with. The single sanctioned deletion — the `audit:prune`
 * retention purge — goes through the *raw* DatabaseInterface, which the last
 * test exercises to prove retention is not collateral damage of the guard.
 */
#[CoversClass(AppendOnlyAuditDatabase::class)]
#[CoversClass(AppendOnlySchema::class)]
final class AuditImmutabilityTest extends TestCase
{
    private DatabaseInterface $raw;
    private AppendOnlyAuditDatabase $auditDb;

    protected function setUp(): void
    {
        $this->raw = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($this->raw)->ensureSchema();
        $this->auditDb = new AppendOnlyAuditDatabase($this->raw);
    }

    private function writeOneEvent(): void
    {
        new AuditEventWriter($this->auditDb)->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 7,
            subjectUri: '/entities/note/abc-123',
            outcome: 'allowed',
            severity: 'info',
        ));
    }

    /** @return list<\Waaseyaa\Audit\Entity\AuditEvent> */
    private function readAll(): array
    {
        return iterator_to_array(new AuditEventQuery($this->raw)->findBy(new AuditQuery()), false);
    }

    #[Test]
    public function a_written_event_cannot_be_updated_through_the_audit_database(): void
    {
        $this->writeOneEvent();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->update('audit_event');
    }

    #[Test]
    public function a_written_event_cannot_be_deleted_through_the_audit_database(): void
    {
        $this->writeOneEvent();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->delete('audit_event');
    }

    #[Test]
    public function the_written_row_survives_attempted_mutation_and_is_readable(): void
    {
        $this->writeOneEvent();

        $before = $this->readAll();
        $this->assertCount(1, $before);
        $this->assertSame(AuditEventKind::EntityWrite->value, $before[0]->getEventKind());
        $this->assertSame(7, $before[0]->getAccountUid());

        // Every mutation attempt through the audit database is refused...
        foreach (['update', 'delete'] as $op) {
            try {
                $this->auditDb->{$op}('audit_event');
                $this->fail("audit_event $op should have thrown");
            } catch (\LogicException) {
                // expected
            }
        }

        // ...and the row is byte-for-byte intact afterward.
        $after = $this->readAll();
        $this->assertCount(1, $after);
        $this->assertSame($before[0]->getEventKind(), $after[0]->getEventKind());
        $this->assertSame($before[0]->getAccountUid(), $after[0]->getAccountUid());
        $this->assertSame($before[0]->getSubjectUri(), $after[0]->getSubjectUri());
        $this->assertSame((string) $before[0]->get('uuid'), (string) $after[0]->get('uuid'));
    }

    #[Test]
    public function the_sanctioned_prune_path_through_the_raw_database_can_still_delete(): void
    {
        $this->writeOneEvent();
        $this->assertCount(1, $this->readAll());

        // The one legal deletion path: audit:prune deletes via the raw
        // DatabaseInterface, deliberately bypassing the append-only decorator.
        $this->raw->delete('audit_event')->execute();

        $this->assertCount(0, $this->readAll(), 'Retention purge via the raw database must still work');
    }

    // ------------------------------------------------------------------
    // FR-008 / SC-003 (#1648, additions only): raw SQL through the
    // decorator's query() can no longer mutate audit rows either.
    // ------------------------------------------------------------------

    #[Test]
    public function a_written_event_cannot_be_mutated_by_raw_sql_through_the_audit_database(): void
    {
        $this->writeOneEvent();
        $before = $this->readAll();
        $this->assertCount(1, $before);

        $mutations = [
            "UPDATE audit_event SET outcome = 'denied'",
            'DELETE FROM audit_event',
            'DROP TABLE audit_event',
            'ALTER TABLE audit_event ADD tampered INT',
        ];

        foreach ($mutations as $sql) {
            try {
                iterator_to_array($this->auditDb->query($sql));
                $this->fail(sprintf('Raw SQL "%s" should have thrown \LogicException', $sql));
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // The row is byte-for-byte intact after every blocked attempt.
        $after = $this->readAll();
        $this->assertCount(1, $after);
        $this->assertSame($before[0]->getEventKind(), $after[0]->getEventKind());
        $this->assertSame($before[0]->getAccountUid(), $after[0]->getAccountUid());
        $this->assertSame((string) $before[0]->get('uuid'), (string) $after[0]->get('uuid'));
    }

    #[Test]
    public function a_raw_select_through_the_audit_database_still_works(): void
    {
        $this->writeOneEvent();

        $rows = [];
        foreach ($this->auditDb->query('SELECT event_kind, account_uid FROM audit_event') as $row) {
            $rows[] = (array) $row;
        }

        $this->assertCount(1, $rows);
        $this->assertSame(AuditEventKind::EntityWrite->value, $rows[0]['event_kind']);
        $this->assertSame(7, (int) $rows[0]['account_uid']);
    }

    // ------------------------------------------------------------------
    // W2-2: AppendOnlySchema — schema() DDL guard (FR-003 extension).
    // ------------------------------------------------------------------

    #[Test]
    public function schema_drop_table_on_audit_event_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->schema()->dropTable('audit_event');
    }

    #[Test]
    public function schema_drop_field_on_audit_event_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->schema()->dropField('audit_event', 'event_kind');
    }

    #[Test]
    public function schema_drop_index_on_audit_event_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->auditDb->schema()->dropIndex('audit_event', 'audit_event_account_uid');
    }

    #[Test]
    public function schema_drop_table_on_non_append_only_table_passes_through(): void
    {
        // Create a throwaway table then immediately drop it — verifies the guard
        // does not interfere with non-audit-event tables.
        $this->auditDb->schema()->createTable('w2_2_tmp', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->auditDb->schema()->tableExists('w2_2_tmp'));

        // Must not throw — guard only applies to append-only tables.
        $this->auditDb->schema()->dropTable('w2_2_tmp');

        $this->assertFalse($this->auditDb->schema()->tableExists('w2_2_tmp'));
    }

    #[Test]
    public function schema_add_field_on_audit_event_is_not_blocked(): void
    {
        // Additive DDL on audit_event must not be blocked — legitimate
        // migrations extend the table while the append-only guard only
        // refuses destructive operations.
        $this->assertFalse($this->auditDb->schema()->fieldExists('audit_event', 'w2_2_extra'));

        // Must not throw a LogicException — additive DDL passes through.
        $this->auditDb->schema()->addField('audit_event', 'w2_2_extra', [
            'type' => 'text',
            'not null' => false,
        ]);

        $this->assertTrue($this->auditDb->schema()->fieldExists('audit_event', 'w2_2_extra'));
    }
}
