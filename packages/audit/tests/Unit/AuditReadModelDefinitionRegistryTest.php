<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\ReadModel\AuditReadModelDefinitionRegistry;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\ApprovalEventSchema;
use Waaseyaa\Database\DBALDatabase;

final class AuditReadModelDefinitionRegistryTest extends TestCase
{
    #[Test]
    public function unregistered_audit_rows_have_explicit_field_read_definitions(): void
    {
        $registry = new AuditReadModelDefinitionRegistry();

        self::assertSame(FieldReadLevel::Internal, $registry->level('audit_event', 'attributes'));
        self::assertSame(FieldReadLevel::Internal, $registry->level('audit_checkpoint', 'checkpoint_hash'));
        self::assertSame(FieldReadLevel::Internal, $registry->level('privileged_read_ledger', 'descriptor'));
        self::assertSame(FieldReadLevel::Internal, $registry->level('mcp_approval_event', 'safe_arguments'));
        self::assertSame(FieldReadLevel::Internal, $registry->level('mcp_approval_event', 'principal_key'));
        self::assertSame(FieldReadLevel::Public, $registry->level('audit_event', 'id'));
        self::assertNull($registry->level('unknown_table', 'id'));
    }

    #[Test]
    public function definitions_have_exact_parity_with_every_flat_audit_table(): void
    {
        $database = DBALDatabase::createSqlite();
        (new AuditEventSchemaHandler($database))->ensureSchema();
        (new ApprovalEventSchema($database))->ensure();
        $definitions = (new AuditReadModelDefinitionRegistry())->definitions();

        foreach (['audit_event', 'audit_retention_policy', 'audit_checkpoint', 'privileged_read_ledger', 'mcp_approval_event'] as $table) {
            $columns = [];
            foreach ($database->query('PRAGMA table_info('.$table.')') as $row) {
                $columns[] = (string) $row['name'];
            }
            sort($columns);
            $declared = array_keys($definitions[$table] ?? []);
            sort($declared);
            self::assertSame($columns, $declared, $table.' definitions must neither omit nor invent columns.');
        }
    }
}
