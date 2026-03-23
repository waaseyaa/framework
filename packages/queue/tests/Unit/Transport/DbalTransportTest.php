<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\Transport\DbalTransport;

#[CoversClass(DbalTransport::class)]
final class DbalTransportTest extends TestCase
{
    private DBALDatabase $database;
    private DbalTransport $transport;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->createTable();
        $this->transport = new DbalTransport($this->database);
    }

    private function createTable(): void
    {
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
    }

    #[Test]
    public function pushAndPopJob(): void
    {
        $this->transport->push('default', 'test-payload');

        $job = $this->transport->pop('default');

        self::assertNotNull($job);
        self::assertSame('test-payload', $job['payload']);
        self::assertSame(0, $job['attempts']);
    }

    #[Test]
    public function popReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->transport->pop('default'));
    }

    #[Test]
    public function popRespectsAvailableAt(): void
    {
        // Push a job with 1-hour delay
        $this->transport->push('default', 'delayed', 3600);

        // Should not be available yet
        self::assertNull($this->transport->pop('default'));
    }

    #[Test]
    public function ackRemovesJob(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->ack($job['id']);

        // Job should be gone from the table entirely
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function rejectRemovesJob(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->reject($job['id']);

        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function releaseReturnsJobToQueue(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->release($job['id'], 0);

        // Job should be available again
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function releaseIncrementsAttempts(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');
        self::assertSame(0, $job['attempts']);

        $this->transport->release($job['id'], 0);
        $job2 = $this->transport->pop('default');

        self::assertNotNull($job2);
        self::assertSame(1, $job2['attempts']);
    }

    #[Test]
    public function sizeCountsPendingJobs(): void
    {
        self::assertSame(0, $this->transport->size('default'));

        $this->transport->push('default', 'a');
        $this->transport->push('default', 'b');

        self::assertSame(2, $this->transport->size('default'));

        // Reserved jobs don't count
        $this->transport->pop('default');
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function purgeRemovesAllJobs(): void
    {
        $this->transport->push('default', 'a');
        $this->transport->push('default', 'b');

        $this->transport->purge('default');

        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function queuesAreIsolated(): void
    {
        $this->transport->push('high', 'important');
        $this->transport->push('low', 'background');

        self::assertSame(1, $this->transport->size('high'));
        self::assertSame(1, $this->transport->size('low'));

        $job = $this->transport->pop('high');
        self::assertSame('important', $job['payload']);
    }

    #[Test]
    public function popProcessesInFifoOrder(): void
    {
        $this->transport->push('default', 'first');
        $this->transport->push('default', 'second');
        $this->transport->push('default', 'third');

        $job1 = $this->transport->pop('default');
        $job2 = $this->transport->pop('default');
        $job3 = $this->transport->pop('default');

        self::assertSame('first', $job1['payload']);
        self::assertSame('second', $job2['payload']);
        self::assertSame('third', $job3['payload']);
    }
}
