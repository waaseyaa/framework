<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Adds the `locked_by` owner token to `waaseyaa_schedule_locks` so a lock can
 * only be released by the node that acquired it (scheduler m15 — closes the
 * split-brain double-run window where a stale node's un-scoped release deleted
 * another node's reclaimed live lock).
 *
 * Additive + idempotent: guarded by hasColumn() so it is safe to re-run and a
 * no-op on fresh installs (the create migration already includes the column).
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasColumn('waaseyaa_schedule_locks', 'locked_by')) {
            return;
        }

        $schema->getConnection()->executeStatement(
            "ALTER TABLE waaseyaa_schedule_locks ADD COLUMN locked_by VARCHAR(64) NOT NULL DEFAULT ''",
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Non-destructive: leave the column in place on rollback (other nodes may
        // still rely on it; dropping a column is a table rebuild on SQLite).
    }
};
