<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('publishing_idempotency')) {
            return;
        }
        $schema->getConnection()->executeStatement(
            'CREATE TABLE publishing_idempotency (
                idem_key VARCHAR(191) PRIMARY KEY NOT NULL,
                operation VARCHAR(128) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                response_json TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )',
        );
    }

    public function down(SchemaBuilder $schema): void {}
};
