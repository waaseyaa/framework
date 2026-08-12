<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Owns the shared, bin-keyed disposable cache projection. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('cache_items')) {
            return;
        }

        $schema->getConnection()->executeStatement(
            'CREATE TABLE cache_items (
                bin VARCHAR(128) NOT NULL,
                cid VARCHAR(255) NOT NULL,
                data BLOB NOT NULL,
                expire INTEGER NOT NULL DEFAULT -1,
                created INTEGER NOT NULL DEFAULT 0,
                tags TEXT NOT NULL DEFAULT \'\',
                valid INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (bin, cid)
            )',
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only disposable-projection migration.
    }
};
