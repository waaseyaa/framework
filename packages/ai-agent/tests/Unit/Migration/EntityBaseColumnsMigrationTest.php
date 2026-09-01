<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class EntityBaseColumnsMigrationTest extends TestCase
{
    #[Test]
    public function package_declares_and_idempotently_adds_required_entity_base_columns(): void
    {
        $packageRoot = \dirname(__DIR__, 3);
        $composer = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('migrations', $composer['extra']['waaseyaa']['migrations'] ?? null);

        $database = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($database->getConnection());
        $initial = require $packageRoot . '/migrations/2026_05_18_000001_create_agent_run.php';
        $upgrade = require $packageRoot . '/migrations/2026_09_01_000001_add_entity_base_columns.php';
        \assert($initial instanceof Migration);
        \assert($upgrade instanceof Migration);

        $initial->up($schema);
        $schemaManager = $database->getConnection()->createSchemaManager();
        foreach ([
            ['idx_agent_run_status_queued_at', 'agent_run'],
            ['idx_agent_run_account_queued_at', 'agent_run'],
            ['idx_agent_run_status_started_at', 'agent_run'],
            ['idx_agent_audit_run_occurred_at', 'agent_audit_log'],
        ] as [$index, $table]) {
            $schemaManager->dropIndex($index, $table);
        }
        foreach (['agent_run', 'agent_audit_log'] as $table) {
            self::assertFalse($schema->hasColumn($table, 'bundle'));
            self::assertFalse($schema->hasColumn($table, 'langcode'));
        }

        $upgrade->up($schema);
        $upgrade->up($schema);

        foreach (['agent_run', 'agent_audit_log'] as $table) {
            self::assertTrue($schema->hasColumn($table, '_data'));
            self::assertTrue($schema->hasColumn($table, 'bundle'));
            self::assertTrue($schema->hasColumn($table, 'langcode'));
        }
        self::assertSame(
            [
                'idx_agent_run_account_queued_at',
                'idx_agent_run_status_queued_at',
                'idx_agent_run_status_started_at',
            ],
            $this->nonPrimaryIndexNames($schemaManager->listTableIndexes('agent_run')),
        );
        self::assertSame(
            ['idx_agent_audit_run_occurred_at'],
            $this->nonPrimaryIndexNames($schemaManager->listTableIndexes('agent_audit_log')),
        );
    }

    #[Test]
    public function materialized_fresh_tables_receive_the_complete_entity_base_shape(): void
    {
        $database = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($database->getConnection());
        $connection = $database->getConnection();
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE agent_run (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                account_id BIGINT NOT NULL,
                status VARCHAR(32) NOT NULL,
                queued_at VARCHAR(35) NOT NULL,
                started_at VARCHAR(35) DEFAULT NULL
            )
            SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE agent_audit_log (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                run_id VARCHAR(36) NOT NULL,
                occurred_at VARCHAR(35) NOT NULL
            )
            SQL);

        $upgrade = require \dirname(__DIR__, 3) . '/migrations/2026_09_01_000001_add_entity_base_columns.php';
        \assert($upgrade instanceof Migration);

        $upgrade->up($schema);
        $upgrade->up($schema);

        foreach (['agent_run', 'agent_audit_log'] as $table) {
            self::assertTrue($schema->hasColumn($table, '_data'));
            self::assertTrue($schema->hasColumn($table, 'bundle'));
            self::assertTrue($schema->hasColumn($table, 'langcode'));
        }
        self::assertSame("'{}'", $connection->fetchOne("SELECT dflt_value FROM pragma_table_info('agent_run') WHERE name = '_data'"));
        self::assertSame("'{}'", $connection->fetchOne("SELECT dflt_value FROM pragma_table_info('agent_audit_log') WHERE name = '_data'"));
    }

    /**
     * @param array<string, \Doctrine\DBAL\Schema\Index> $indexes
     * @return list<string>
     */
    private function nonPrimaryIndexNames(array $indexes): array
    {
        $names = [];
        foreach ($indexes as $index) {
            if (!$index->isPrimary()) {
                $names[] = $index->getName();
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }
}
