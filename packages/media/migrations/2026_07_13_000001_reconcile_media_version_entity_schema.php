<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Adds the common content-entity columns omitted by the original CAS schema.
 *
 * The CAS columns remain untouched and the subsystem stays parked under #1742.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('media_version')) {
            return;
        }

        $connection = $schema->getConnection();
        foreach (['bundle', 'label', 'langcode'] as $column) {
            if ($schema->hasColumn('media_version', $column)) {
                continue;
            }

            $default = $column === 'langcode' ? 'en' : '';
            $connection->executeStatement(sprintf(
                "ALTER TABLE media_version ADD COLUMN %s TEXT NOT NULL DEFAULT '%s'",
                $column,
                $default,
            ));
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive schema-honesty migration: rollback is intentionally a no-op.
    }
};
