<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\InMemoryQueue;
use Waaseyaa\Queue\Message\EntityMessage;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\QueueInterface;

final class InMemoryQueueTest extends TestCase
{
    public function testImplementsQueueInterface(): void
    {
        $queue = new InMemoryQueue();

        $this->assertInstanceOf(QueueInterface::class, $queue);
    }

    public function testDispatchStoresMessages(): void
    {
        $queue = new InMemoryQueue();
        $message = new EntityMessage(
            entityTypeId: 'node',
            entityId: 1,
            operation: 'insert',
        );

        $queue->dispatch($message);

        $messages = $queue->getMessages();
        $this->assertCount(1, $messages);
        $this->assertSame($message, $messages[0]);
    }

    public function testDispatchMultipleMessages(): void
    {
        $queue = new InMemoryQueue();
        $message1 = new EntityMessage(
            entityTypeId: 'node',
            entityId: 1,
            operation: 'insert',
        );
        $message2 = new GenericMessage(
            type: 'cache.clear',
        );

        $queue->dispatch($message1);
        $queue->dispatch($message2);

        $messages = $queue->getMessages();
        $this->assertCount(2, $messages);
        $this->assertSame($message1, $messages[0]);
        $this->assertSame($message2, $messages[1]);
    }

    public function testGetMessagesReturnsEmptyArrayInitially(): void
    {
        $queue = new InMemoryQueue();

        $this->assertSame([], $queue->getMessages());
    }

    public function testClearRemovesAllMessages(): void
    {
        $queue = new InMemoryQueue();
        $queue->dispatch(new GenericMessage(type: 'test'));
        $queue->dispatch(new GenericMessage(type: 'test2'));

        $queue->clear();

        $this->assertSame([], $queue->getMessages());
    }

    public function testClearOnEmptyQueue(): void
    {
        $queue = new InMemoryQueue();

        $queue->clear();

        $this->assertSame([], $queue->getMessages());
    }
}
