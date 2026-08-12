<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Owns durable SSE broadcast state formerly installed by HTTP construction. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();
        if (!$schema->hasTable('_broadcast_log')) {
            $connection->executeStatement(
                'CREATE TABLE _broadcast_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel TEXT NOT NULL,
                    event TEXT NOT NULL,
                    data TEXT NOT NULL DEFAULT \'{}\',
                    created_at REAL NOT NULL
                )',
            );
        }
        if (!$schema->hasTable('_broadcast_retained')) {
            $connection->executeStatement(
                'CREATE TABLE _broadcast_retained (
                    channel TEXT NOT NULL,
                    retain_key TEXT NOT NULL,
                    msg_id INTEGER NOT NULL,
                    event TEXT NOT NULL,
                    data TEXT NOT NULL DEFAULT \'{}\',
                    created_at REAL NOT NULL,
                    expires_at REAL,
                    PRIMARY KEY (channel, retain_key)
                )',
            );
        }
    }

    public function down(SchemaBuilder $schema): void {}
};
