<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Migration;

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class CreateQueueTables extends Migration
{
    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        $conn->executeStatement('
            CREATE TABLE IF NOT EXISTS waaseyaa_queue_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL,
                reserved_at INTEGER,
                created_at INTEGER NOT NULL
            )
        ');

        $conn->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_queue_available
            ON waaseyaa_queue_jobs (queue, available_at)
        ');

        $conn->executeStatement('
            CREATE TABLE IF NOT EXISTS waaseyaa_failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at VARCHAR(50) NOT NULL,
                retried_at VARCHAR(50)
            )
        ');
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('waaseyaa_failed_jobs');
        $schema->dropIfExists('waaseyaa_queue_jobs');
    }
}
