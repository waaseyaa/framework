<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\Transport\DbalTransport;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Concrete contract test that exercises the abstract suite against
 * {@see DbalTransport} backed by an in-memory SQLite database. Schema mirrors
 * the production migration ({@see \Waaseyaa\Queue\Migration\CreateQueueTables}).
 */
#[CoversNothing]
final class DbalTransportContractTest extends TransportContractTest
{
    private DBALDatabase $database;

    protected function makeTransport(): TransportInterface
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->schema()->createTable('waaseyaa_queue_jobs', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'queue' => ['type' => 'varchar', 'not null' => true],
                'payload' => ['type' => 'text', 'not null' => true],
                'attempts' => ['type' => 'int', 'not null' => true, 'default' => 0],
                'available_at' => ['type' => 'int', 'not null' => true],
                'reserved_at' => ['type' => 'int'],
                'created_at' => ['type' => 'int', 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_queue_available' => ['queue', 'available_at'],
            ],
        ]);

        return new DbalTransport($this->database);
    }
}
