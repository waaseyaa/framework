<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Integrity\AuditCheckpointHasher;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Integration proof for WP1: schema columns, checkpoint table, genesis anchor.
 *
 * All assertions run against an in-memory SQLite instance created by
 * DBALDatabase::createSqlite() to keep the test hermetic and fast.
 */
#[CoversClass(AuditEventSchemaHandler::class)]
#[CoversClass(AuditEventCanonicalizer::class)]
#[CoversClass(AuditCheckpointHasher::class)]
final class AuditChainSchemaTest extends TestCase
{
    private DatabaseInterface $raw;
    private AuditEventSchemaHandler $handler;

    protected function setUp(): void
    {
        $this->raw = DBALDatabase::createSqlite();
        $this->handler = new AuditEventSchemaHandler($this->raw);
    }

    // ------------------------------------------------------------------
    // Column existence
    // ------------------------------------------------------------------

    #[Test]
    public function audit_event_has_row_hash_column_after_ensure_schema(): void
    {
        $this->handler->ensureSchema();

        $this->assertTrue(
            $this->raw->schema()->fieldExists('audit_event', 'row_hash'),
            'audit_event must have row_hash column after ensureSchema()',
        );
    }

    #[Test]
    public function audit_event_has_prev_hash_column_after_ensure_schema(): void
    {
        $this->handler->ensureSchema();

        $this->assertTrue(
            $this->raw->schema()->fieldExists('audit_event', 'prev_hash'),
            'audit_event must have prev_hash column after ensureSchema()',
        );
    }

    // ------------------------------------------------------------------
    // audit_checkpoint table and columns
    // ------------------------------------------------------------------

    #[Test]
    public function audit_checkpoint_table_exists_after_ensure_schema(): void
    {
        $this->handler->ensureSchema();

        $this->assertTrue($this->raw->schema()->tableExists('audit_checkpoint'));
    }

    #[Test]
    public function audit_checkpoint_has_all_required_columns(): void
    {
        $this->handler->ensureSchema();

        $requiredColumns = [
            'id', 'uuid', 'segment_start_id', 'segment_end_id', 'row_count',
            'segment_hash', 'prev_checkpoint_hash', 'checkpoint_hash',
            'signature', 'hash_version', 'is_genesis', 'created_at',
        ];

        foreach ($requiredColumns as $col) {
            $this->assertTrue(
                $this->raw->schema()->fieldExists('audit_checkpoint', $col),
                sprintf('audit_checkpoint must have column "%s"', $col),
            );
        }
    }

    // ------------------------------------------------------------------
    // Genesis anchor
    // ------------------------------------------------------------------

    #[Test]
    public function exactly_one_genesis_row_exists_after_ensure_schema_on_empty_db(): void
    {
        $this->handler->ensureSchema();

        $rows = $this->fetchAllCheckpoints();
        $this->assertCount(1, $rows, 'Exactly one genesis checkpoint must be inserted on first ensureSchema()');
        $this->assertSame(1, (int) $rows[0]['is_genesis']);
    }

    #[Test]
    public function fresh_genesis_is_authenticated_when_a_derived_key_is_supplied(): void
    {
        $key = random_bytes(32);
        new AuditEventSchemaHandler($this->raw, $key)->ensureSchema();

        $signature = (string) $this->fetchAllCheckpoints()[0]['signature'];

        self::assertSame(
            'hmac-sha256.hkdf-v1:' . hash_hmac('sha256', (string) $this->fetchAllCheckpoints()[0]['checkpoint_hash'], $key),
            $signature,
        );
        self::assertFalse(str_contains($signature, $key));
    }

    #[Test]
    public function genesis_row_has_genesis_hash_as_prev_checkpoint_hash(): void
    {
        $this->handler->ensureSchema();

        $genesis = $this->fetchAllCheckpoints()[0];
        $this->assertSame(
            AuditEventCanonicalizer::GENESIS_HASH,
            $genesis['prev_checkpoint_hash'],
            'Genesis row prev_checkpoint_hash must equal GENESIS_HASH',
        );
    }

    #[Test]
    public function genesis_row_segment_end_id_equals_max_audit_event_id(): void
    {
        // Write two events first (before ensureSchema sets up the checkpoint table).
        // We need to set up the audit_event table first without the checkpoint table.
        // Use a fresh DB and run ensureSchema on it, write two events, then
        // simulate a "fresh" schema call by reusing the same DB (idempotent).
        $raw = DBALDatabase::createSqlite();
        $handler = new AuditEventSchemaHandler($raw);

        // First call: sets up audit_event and checkpoint table, inserts genesis
        // with no events (segment_end_id = 0).
        $handler->ensureSchema();

        // Write two audit events through the append-only decorator.
        $auditDb = new AppendOnlyAuditDatabase($raw);
        $writer = new AuditEventWriter($auditDb);
        $writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 7,
            subjectUri: '/entities/note/test-1',
            outcome: 'allowed',
            severity: 'info',
        ));
        $writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 7,
            subjectUri: '/entities/note/test-2',
            outcome: 'allowed',
            severity: 'info',
        ));

        // Now create a NEW in-memory database and simulate an "upgrade" scenario:
        // create another fresh DB that already has events, then call ensureSchema
        // for the first time (no checkpoint rows yet).
        $raw2 = DBALDatabase::createSqlite();
        $handler2 = new AuditEventSchemaHandler($raw2);

        // Manually create audit_event table (mimicking a pre-WP1 install) and
        // insert two events without the checkpoint table.
        $conn2 = $raw2->getConnection();
        $conn2->executeStatement(
            "CREATE TABLE IF NOT EXISTS audit_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                uuid VARCHAR(128) NOT NULL DEFAULT '',
                event_kind VARCHAR(64) NOT NULL DEFAULT '',
                account_uid INTEGER NOT NULL DEFAULT 0,
                actor_uid INTEGER NULL,
                entity_type_id VARCHAR(128) NOT NULL DEFAULT '',
                entity_uuid VARCHAR(128) NOT NULL DEFAULT '',
                subject_uri VARCHAR(512) NOT NULL DEFAULT '',
                outcome VARCHAR(16) NOT NULL DEFAULT 'allowed',
                severity VARCHAR(16) NOT NULL DEFAULT 'info',
                attributes TEXT NOT NULL DEFAULT '{}',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
        );
        $conn2->executeStatement("INSERT INTO audit_event (uuid, event_kind, account_uid, subject_uri, outcome, severity, attributes) VALUES ('u-1', 'entity.write', 7, '/a', 'allowed', 'info', '{}')");
        $conn2->executeStatement("INSERT INTO audit_event (uuid, event_kind, account_uid, subject_uri, outcome, severity, attributes) VALUES ('u-2', 'entity.write', 7, '/b', 'allowed', 'info', '{}')");

        // ensureSchema() on this DB — first time seeing it — should produce
        // genesis with segment_end_id = 2 (the current MAX(id)).
        $handler2->ensureSchema();

        $checkpoints2 = iterator_to_array(
            $raw2->select('audit_checkpoint')->execute(),
            false,
        );
        $this->assertCount(1, $checkpoints2);
        $genesis = $checkpoints2[0];
        $this->assertSame(2, (int) $genesis['segment_end_id'], 'segment_end_id must equal MAX(audit_event.id) at genesis time');
        $this->assertSame(2, (int) $genesis['row_count'], 'row_count must equal COUNT(*) of audit_event at genesis time');
        $this->assertSame(1, (int) $genesis['segment_start_id']);
        $this->assertSame(1, (int) $genesis['is_genesis']);
    }

    #[Test]
    public function running_ensure_schema_twice_does_not_add_a_second_genesis_row(): void
    {
        $this->handler->ensureSchema();
        $this->handler->ensureSchema();

        $rows = $this->fetchAllCheckpoints();
        $this->assertCount(1, $rows, 'Running ensureSchema() twice must NOT insert a second genesis row');
    }

    #[Test]
    public function genesis_checkpoint_hash_equals_hasher_recomputed_from_stored_fields(): void
    {
        $this->handler->ensureSchema();

        $genesis = $this->fetchAllCheckpoints()[0];

        $expected = AuditCheckpointHasher::checkpointHash(
            segmentStartId: (int) $genesis['segment_start_id'],
            segmentEndId: (int) $genesis['segment_end_id'],
            rowCount: (int) $genesis['row_count'],
            segmentHash: (string) $genesis['segment_hash'],
            prevCheckpointHash: (string) $genesis['prev_checkpoint_hash'],
        );

        $this->assertSame(
            $expected,
            (string) $genesis['checkpoint_hash'],
            'Genesis checkpoint_hash must equal AuditCheckpointHasher::checkpointHash() recomputed from stored fields',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllCheckpoints(): array
    {
        return iterator_to_array(
            $this->raw->select('audit_checkpoint')->execute(),
            false,
        );
    }
}
