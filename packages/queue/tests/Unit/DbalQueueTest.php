<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\DbalQueue;
use Waaseyaa\Queue\Tests\Unit\Fixtures\HighPriorityJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\InMemoryTransport;

#[CoversClass(DbalQueue::class)]
final class DbalQueueTest extends TestCase
{
    private InMemoryTransport $transport;
    private DbalQueue $queue;

    protected function setUp(): void
    {
        $this->transport = new InMemoryTransport();
        $this->queue = new DbalQueue($this->transport);
    }

    #[Test]
    public function dispatchSerializesAndPushes(): void
    {
        $this->queue->dispatch(new SuccessfulJob());

        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchRespectsOnQueueAttribute(): void
    {
        $this->queue->dispatch(new HighPriorityJob());

        self::assertSame(1, $this->transport->size('high'));
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchAcceptsNonJobMessages(): void
    {
        $message = new \stdClass();
        $message->type = 'test';

        $this->queue->dispatch($message);

        self::assertSame(1, $this->transport->size('default'));
    }
}
