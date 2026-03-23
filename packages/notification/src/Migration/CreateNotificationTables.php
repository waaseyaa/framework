<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Migration;

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class CreateNotificationTables extends Migration
{
    /** @var list<string> */
    public array $after = ['waaseyaa/queue'];

    public function up(SchemaBuilder $schema): void
    {
        $conn = $schema->getConnection();

        $conn->executeStatement('
            CREATE TABLE IF NOT EXISTS waaseyaa_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type VARCHAR(255) NOT NULL,
                notifiable_type VARCHAR(128) NOT NULL,
                notifiable_id VARCHAR(128) NOT NULL,
                data TEXT NOT NULL,
                created_at VARCHAR(50) NOT NULL,
                read_at VARCHAR(50)
            )
        ');

        $conn->executeStatement('
            CREATE INDEX IF NOT EXISTS idx_notifications_notifiable
            ON waaseyaa_notifications (notifiable_type, notifiable_id)
        ');
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('waaseyaa_notifications');
    }
}
