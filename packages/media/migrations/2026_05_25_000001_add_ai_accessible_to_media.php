<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Adds the ai_accessible column to the media table.
 *
 * Tri-state field: 'yes', 'no', 'inherit' (default).
 * Stored as VARCHAR(8) — sufficient for the longest value ('inherit').
 *
 * Idempotent: safe to re-run if the column already exists.
 *
 * Refs: gap-matrix-A5, FR-008, FR-009, C-004.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $this->addColumnIfMissing(
            $schema,
            'media',
            'ai_accessible',
            "VARCHAR(8) NOT NULL DEFAULT 'inherit'",
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping columns is version-dependent; leave no-op.
    }

    private function addColumnIfMissing(SchemaBuilder $schema, string $table, string $column, string $sqliteFragment): void
    {
        if ($schema->hasColumn($table, $column)) {
            return;
        }

        $schema->getConnection()->executeStatement(
            sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $sqliteFragment),
        );
    }
};
