<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/** Retained-red proof for the append-only DB-02 audit succession ledger. */
final class AuditSuccessionSchemaRetainedRedTest extends TestCase
{
    #[Test]
    public function coordinated_runtime_migrations_install_the_succession_and_pruned_evidence_tables(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::audit($database);
        $schema = $database->schema();

        self::assertTrue($schema->tableExists('audit_checkpoint_succession'));
        self::assertTrue($schema->tableExists('audit_checkpoint_succession_pruned'));
        foreach ([
            'sequence', 'record_kind', 'format', 'purpose_id', 'algorithm',
            'from_master_version', 'to_master_version', 'predecessor_key_id',
            'successor_key_id', 'chain_digest', 'pruned_checkpoint_digest',
            'pruned_checkpoint_count', 'checkpoint_count', 'terminal_checkpoint_id',
            'terminal_checkpoint_uuid', 'terminal_segment_end_id',
            'terminal_checkpoint_hash', 'rekey_request_digest', 'registry_checksum',
            'rolled_back_sequence', 'signature', 'previous_ledger_hash',
            'record_hash', 'created_at',
        ] as $column) {
            self::assertTrue($schema->fieldExists('audit_checkpoint_succession', $column), $column);
        }
        foreach (['anchor_sequence', 'checkpoint_id', 'checkpoint_uuid', 'checkpoint_hash'] as $column) {
            self::assertTrue($schema->fieldExists('audit_checkpoint_succession_pruned', $column), $column);
        }
    }

    #[Test]
    public function succession_records_are_engine_level_append_only(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::audit($database);
        $connection = $database->getConnection();
        $connection->executeStatement($this->anchorInsertSql());

        try {
            $connection->executeStatement(
                "UPDATE audit_checkpoint_succession SET algorithm = 'forged' WHERE sequence = 1",
            );
            self::fail('Succession records were mutable.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('append-only');
        $connection->executeStatement('DELETE FROM audit_checkpoint_succession WHERE sequence = 1');
    }

    #[Test]
    public function anchored_pruned_checkpoint_evidence_is_engine_level_append_only(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::audit($database);
        $connection = $database->getConnection();
        $connection->executeStatement($this->anchorInsertSql());
        $connection->executeStatement(
            "INSERT INTO audit_checkpoint_succession_pruned (anchor_sequence, checkpoint_id, checkpoint_uuid, checkpoint_hash) VALUES (1, 1, 'checkpoint-1', '" . str_repeat('9', 64) . "')",
        );

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('append-only');
        $connection->executeStatement(
            'UPDATE audit_checkpoint_succession_pruned SET checkpoint_id = 10 WHERE anchor_sequence = 1',
        );
    }

    private function anchorInsertSql(): string
    {
        $hash = str_repeat('a', 64);
        $zero = str_repeat('0', 64);

        return "INSERT INTO audit_checkpoint_succession (record_kind, format, purpose_id, algorithm, from_master_version, to_master_version, predecessor_key_id, successor_key_id, chain_digest, pruned_checkpoint_digest, pruned_checkpoint_count, checkpoint_count, terminal_checkpoint_id, terminal_checkpoint_uuid, terminal_segment_end_id, terminal_checkpoint_hash, rekey_request_digest, registry_checksum, rolled_back_sequence, signature, previous_ledger_hash, record_hash, created_at) VALUES ('succession-anchor', 'waaseyaa.audit.checkpoint-succession.v1', 'waaseyaa.audit.checkpoint-hmac.v1', 'hmac-sha256', 1, 2, '{$hash}', '{$hash}', '{$hash}', '{$zero}', 0, 1, 1, 'checkpoint-1', 0, '{$hash}', '{$hash}', '{$hash}', NULL, 'signature', '{$zero}', '{$hash}', 1)";
    }
}
