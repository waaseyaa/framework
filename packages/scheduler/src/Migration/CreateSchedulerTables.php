<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Migration;

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class CreateSchedulerTables extends Migration
{
    /** @var list<string> */
    public array $after = ['waaseyaa/queue'];

    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        $conn->executeStatement('
            CREATE TABLE IF NOT EXISTS waaseyaa_schedule_locks (
                task_name VARCHAR(255) PRIMARY KEY,
                locked_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )
        ');

        $conn->executeStatement('
            CREATE TABLE IF NOT EXISTS waaseyaa_schedule_state (
                task_name VARCHAR(255) PRIMARY KEY,
                last_run_at VARCHAR(50) NOT NULL,
                last_result TEXT NOT NULL
            )
        ');
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('waaseyaa_schedule_state');
        $schema->dropIfExists('waaseyaa_schedule_locks');
    }
}
