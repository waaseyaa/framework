<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Adds the one-row cache invalidation authority used by CFG-04 rotation. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $database = $schema->getConnection();
        if (!$schema->hasTable('cache_items')) {
            throw new \RuntimeException('Cache generation migration requires the cache_items schema.');
        }
        if (!$schema->hasColumn('cache_items', 'generation')) {
            $database->executeStatement(
                'ALTER TABLE cache_items ADD COLUMN generation INTEGER NOT NULL DEFAULT 1',
            );
        }
        if (!$schema->hasTable('cache_generation')) {
            $database->executeStatement(
                'CREATE TABLE cache_generation (
                    singleton_id INTEGER PRIMARY KEY,
                    generation INTEGER NOT NULL,
                    CHECK (singleton_id = 1),
                    CHECK (generation > 0)
                )',
            );
            $database->executeStatement(
                'INSERT INTO cache_generation (singleton_id, generation) VALUES (1, 1)',
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only: old payload rows remain harmless behind the active generation.
    }
};
