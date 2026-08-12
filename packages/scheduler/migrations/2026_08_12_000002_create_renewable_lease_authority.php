<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Durable lease and global fencing authority; serving code performs DML only. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();
        if (!$schema->hasTable('waaseyaa_scheduler_fence_sequence')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_scheduler_fence_sequence (
                    singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1),
                    next_fence INTEGER NOT NULL CHECK (next_fence > 0)
                )
                SQL);
            $connection->executeStatement(
                'INSERT INTO waaseyaa_scheduler_fence_sequence (singleton_id, next_fence) VALUES (1, 1)',
            );
        }
        if (!$schema->hasTable('waaseyaa_scheduler_leases')) {
            $connection->executeStatement(<<<'SQL'
                CREATE TABLE waaseyaa_scheduler_leases (
                    lease_domain VARCHAR(255) PRIMARY KEY,
                    owner_token VARCHAR(64) NULL,
                    expires_at_ms INTEGER NOT NULL,
                    fencing_token INTEGER NOT NULL CHECK (fencing_token >= 0),
                    renewal_generation INTEGER NOT NULL CHECK (renewal_generation >= 0),
                    renewal_nonce VARCHAR(64) NULL
                )
                SQL);
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only authority: fence history survives release and upgrades.
    }
};
