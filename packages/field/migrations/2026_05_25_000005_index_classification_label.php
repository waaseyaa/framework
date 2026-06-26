<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Adds an index on the `classification_label` column wherever it exists.
 *
 * Background
 * ----------
 * The `classification_label` field type declares an `index` hint, but nothing
 * in the schema builder honours that signal — FieldDefinition::isIndexed() is
 * the only consumed mechanism and is not set for this column. As a result,
 * every entity table that carries a `classification_label` column is scanned
 * in full by the retention jobs that filter on it (L1-field.md §M1, WP2).
 *
 * What this migration does
 * ------------------------
 * Iterates every table present in the current database. For each table that
 * has a `classification_label` column it issues a
 * `CREATE INDEX IF NOT EXISTS …` statement — making the operation both
 * idempotent and safe to re-run.
 *
 * Index-name scheme
 * -----------------
 * `idx_{table}_classification_label` — used when ≤ 60 characters.
 * `idx_clab_{sha1prefix}` — used otherwise (sha1 of the raw table name,
 * first 16 hex characters); deterministic per table and always ≤ 25 chars.
 *
 * Scope limitation
 * ----------------
 * This migration covers tables that exist at the time it runs. Entity tables
 * created AFTER this migration (by runtime schema sync) are NOT auto-indexed
 * by it. A systemic fix that honours the field-type `index` hint inside the
 * schema builder is a separate concern outside this WP's scope.
 *
 * Additive / idempotent: safe to re-run; `down()` is a no-op per the
 * additive SQLite schema convention used by sibling migrations.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();
        $sm   = $conn->createSchemaManager();

        foreach ($sm->listTableNames() as $table) {
            $columns = $sm->listTableColumns($table);
            if (!array_key_exists('classification_label', $columns)) {
                continue;
            }

            $indexName = $this->buildIndexName($table);

            try {
                $conn->executeStatement(sprintf(
                    'CREATE INDEX IF NOT EXISTS %s ON %s (classification_label)',
                    $indexName,
                    $conn->quoteIdentifier($table),
                ));
            } catch (\Throwable) {
                // A driver that does not support IF NOT EXISTS on CREATE INDEX,
                // or a concurrent create, must not abort the migration loop.
            }
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: no-op on down.
    }

    private function buildIndexName(string $table): string
    {
        $candidate = 'idx_' . $table . '_classification_label';
        if (strlen($candidate) <= 60) {
            return $candidate;
        }

        return 'idx_clab_' . substr(sha1($table), 0, 16);
    }
};
