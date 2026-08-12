<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Owns the optional persistent Foundation rate-limit window store. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('rate_limit_windows')) {
            return;
        }

        $schema->getConnection()->executeStatement(
            'CREATE TABLE rate_limit_windows (
                key TEXT PRIMARY KEY,
                count INTEGER NOT NULL DEFAULT 0,
                window_start INTEGER NOT NULL
            )',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only infrastructure-state migration.
    }
};
