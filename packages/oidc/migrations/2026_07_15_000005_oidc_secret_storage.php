<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();

        foreach (['oidc_access_token', 'oidc_refresh_token'] as $table) {
            if (!$schema->hasTable($table) || $schema->hasColumn($table, 'token_lookup')) {
                continue;
            }

            $connection->executeStatement(sprintf('ALTER TABLE %s ADD COLUMN token_lookup CHAR(64) NULL', $table));
            $connection->executeStatement(sprintf('CREATE UNIQUE INDEX idx_%s_lookup ON %s (token_lookup)', $table, $table));
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only custody migration.
    }
};
