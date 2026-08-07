<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use Waaseyaa\Queue\MessageBusQueue;
use Waaseyaa\Queue\Message\EntityMessage;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessageBusQueueTest extends TestCase
{
    public function testImplementsQueueInterface(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $queue = new MessageBusQueue($bus);

        $this->assertInstanceOf(QueueInterface::class, $queue);
    }

    public function testDispatchDelegatesToMessageBus(): void
    {
        $message = new EntityMessage(
            entityTypeId: 'node',
            entityId: 42,
            operation: 'insert',
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo($message))
            ->willReturn(new Envelope($message));

        $queue = new MessageBusQueue($bus);
        $queue->dispatch($message);
    }

    public function testDispatchWithGenericMessage(): void
    {
        $message = new GenericMessage(
            type: 'email.send',
            payload: ['to' => 'user@example.com'],
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo($message))
            ->willReturn(new Envelope($message));

        $queue = new MessageBusQueue($bus);
        $queue->dispatch($message);
    }
}
