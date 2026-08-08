<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Materialises the classification_label_definition entity table.
 *
 * Each row defines one classification label available in the system. The nine
 * canonical labels are seeded via
 * packages/field/defaults/classification-labels.yaml.
 *
 * Idempotent: safe to re-run if the table already exists.
 *
 * Refs: gap-matrix-A4, DIR-004
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        if (!$schema->hasTable('classification_label_definition')) {
            $conn->executeStatement(
                'CREATE TABLE classification_label_definition (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                    label_id VARCHAR(64) NOT NULL DEFAULT \'\',
                    display_name VARCHAR(255) NOT NULL DEFAULT \'\',
                    confidentiality_level INTEGER NOT NULL DEFAULT 0,
                    bundle VARCHAR(128) NOT NULL DEFAULT \'\',
                    langcode VARCHAR(12) NOT NULL DEFAULT \'en\',
                    _data TEXT NOT NULL DEFAULT \'{}\'
                )',
            );

            $conn->executeStatement(
                'CREATE UNIQUE INDEX classification_label_definition_uuid
                 ON classification_label_definition (uuid)',
            );

            $conn->executeStatement(
                'CREATE UNIQUE INDEX classification_label_definition_label_id
                 ON classification_label_definition (label_id)',
            );

            $conn->executeStatement(
                'CREATE INDEX classification_label_definition_level
                 ON classification_label_definition (confidentiality_level)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: no-op on down.
    }
};
