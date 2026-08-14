<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Preserved historical migration identity.
 *
 * Installations may already have recorded this version, so it cannot be
 * removed or repurposed. The complete migration-owned audit schema is defined
 * by 2026_08_12_000003_audit_runtime_schema and the two migrations that follow
 * it. Runtime services validate that schema and never create or alter it.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        // Intentionally empty; see the class-level migration-identity note.
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping tables is version-dependent; left as no-op.
    }
};
