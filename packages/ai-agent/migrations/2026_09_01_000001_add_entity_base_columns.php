<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Aligns the AI aggregate tables with the framework sql-column base schema.
 *
 * The original package migration predates the mandatory bundle/langcode base
 * columns. Existing sites need an additive migration; changing the already-run
 * migration would invalidate its checksum and strand upgrades.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        foreach (['agent_run', 'agent_audit_log'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $this->addColumnIfMissing($schema, $table, '_data', "TEXT NOT NULL DEFAULT '{}'");
            $this->addColumnIfMissing($schema, $table, 'bundle', "VARCHAR(128) NOT NULL DEFAULT ''");
            $this->addColumnIfMissing($schema, $table, 'langcode', "VARCHAR(12) NOT NULL DEFAULT 'en'");
        }

        $this->ensureIndex($schema, 'agent_run', 'idx_agent_run_status_queued_at', ['status', 'queued_at']);
        $this->ensureIndex($schema, 'agent_run', 'idx_agent_run_account_queued_at', ['account_id', 'queued_at']);
        $this->ensureIndex($schema, 'agent_run', 'idx_agent_run_status_started_at', ['status', 'started_at']);
        $this->ensureIndex($schema, 'agent_audit_log', 'idx_agent_audit_run_occurred_at', ['run_id', 'occurred_at']);
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive compatibility columns are retained on rollback.
    }

    private function addColumnIfMissing(
        SchemaBuilder $schema,
        string $table,
        string $column,
        string $definition,
    ): void {
        if ($schema->hasColumn($table, $column)) {
            return;
        }

        $connection = $schema->getConnection();
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $platform->quoteIdentifier($table),
            $platform->quoteIdentifier($column),
            $definition,
        ));
    }

    /** @param non-empty-list<string> $columns */
    private function ensureIndex(
        SchemaBuilder $schema,
        string $table,
        string $index,
        array $columns,
    ): void {
        if (!$schema->hasTable($table)) {
            return;
        }

        $connection = $schema->getConnection();
        foreach ($connection->createSchemaManager()->listTableIndexes($table) as $existing) {
            if (strcasecmp($existing->getName(), $index) === 0) {
                return;
            }
        }

        $platform = $connection->getDatabasePlatform();
        $quotedColumns = array_map($platform->quoteIdentifier(...), $columns);
        $connection->executeStatement(sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $platform->quoteIdentifier($index),
            $platform->quoteIdentifier($table),
            implode(', ', $quotedColumns),
        ));
    }
};
