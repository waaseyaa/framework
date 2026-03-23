<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Transport\InMemoryTransport;

#[CoversClass(InMemoryTransport::class)]
final class InMemoryTransportTest extends TestCase
{
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new InMemoryTransport();
    }

    #[Test]
    public function pushAndPopJob(): void
    {
        $this->transport->push('default', 'serialized-job');

        $job = $this->transport->pop('default');

        self::assertNotNull($job);
        self::assertSame('serialized-job', $job['payload']);
        self::assertSame(0, $job['attempts']);
    }

    #[Test]
    public function popReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->transport->pop('default'));
    }

    #[Test]
    public function ackRemovesFromReserved(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->ack($job['id']);

        self::assertEmpty($this->transport->getReserved());
    }

    #[Test]
    public function releaseReturnsJobToQueue(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->release($job['id'], 0);

        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function sizeReflectsQueueDepth(): void
    {
        self::assertSame(0, $this->transport->size('default'));

        $this->transport->push('default', 'a');
        $this->transport->push('default', 'b');

        self::assertSame(2, $this->transport->size('default'));
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

        self::assertNull($this->transport->pop('empty'));
    }
}
