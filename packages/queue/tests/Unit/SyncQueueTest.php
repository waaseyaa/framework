<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use Waaseyaa\Queue\Attribute\RateLimited;
use Waaseyaa\Queue\Attribute\UniqueJob;
use Waaseyaa\Queue\AttributeGuard;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\Message\EntityMessage;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\SyncQueue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncQueue::class)]
#[CoversClass(AttributeGuard::class)]
final class SyncQueueTest extends TestCase
{
    public function testImplementsQueueInterface(): void
    {
        $queue = new SyncQueue();

        $this->assertInstanceOf(QueueInterface::class, $queue);
    }

    public function testDispatchInvokesMatchingHandler(): void
    {
        $handled = [];

        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (object $message) use (&$handled): void {
                $handled[] = $message;
            });

        $queue = new SyncQueue([$handler]);
        $message = new GenericMessage(type: 'test');

        $queue->dispatch($message);

        $this->assertCount(1, $handled);
        $this->assertSame($message, $handled[0]);
    }

    public function testDispatchSkipsNonMatchingHandlers(): void
    {
        $supportingHandler = $this->createMock(HandlerInterface::class);
        $supportingHandler->method('supports')->willReturn(true);
        $supportingHandler->expects($this->once())->method('handle');

        $nonSupportingHandler = $this->createMock(HandlerInterface::class);
        $nonSupportingHandler->method('supports')->willReturn(false);
        $nonSupportingHandler->expects($this->never())->method('handle');

        $queue = new SyncQueue([$nonSupportingHandler, $supportingHandler]);

        $queue->dispatch(new GenericMessage(type: 'test'));
    }

    public function testDispatchInvokesMultipleMatchingHandlers(): void
    {
        $handler1 = $this->createMock(HandlerInterface::class);
        $handler1->method('supports')->willReturn(true);
        $handler1->expects($this->once())->method('handle');

        $handler2 = $this->createMock(HandlerInterface::class);
        $handler2->method('supports')->willReturn(true);
        $handler2->expects($this->once())->method('handle');

        $queue = new SyncQueue([$handler1, $handler2]);

        $queue->dispatch(new EntityMessage(
            entityTypeId: 'node',
            entityId: 1,
            operation: 'insert',
        ));
    }

    public function testDispatchWithNoHandlers(): void
    {
        $queue = new SyncQueue();

        // Should not throw any exception.
        $queue->dispatch(new GenericMessage(type: 'orphan'));

        $this->assertTrue(true);
    }

    public function testHandlerFiltersByMessageType(): void
    {
        $entityHandler = $this->createMock(HandlerInterface::class);
        $entityHandler->method('supports')
            ->willReturnCallback(fn(object $msg): bool => $msg instanceof EntityMessage);
        $entityHandler->expects($this->once())->method('handle');

        $genericHandler = $this->createMock(HandlerInterface::class);
        $genericHandler->method('supports')
            ->willReturnCallback(fn(object $msg): bool => $msg instanceof GenericMessage);
        $genericHandler->expects($this->never())->method('handle');

        $queue = new SyncQueue([$entityHandler, $genericHandler]);

        $queue->dispatch(new EntityMessage(
            entityTypeId: 'node',
            entityId: 5,
            operation: 'update',
        ));
    }

    // -----------------------------------------------------------------------
    // Attribute enforcement via SyncQueue::dispatch()
    // -----------------------------------------------------------------------

    #[Test]
    public function uniqueJobIsHandledOnFirstDispatchOnly(): void
    {
        $handledCount = 0;

        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(
            function () use (&$handledCount): void {
                $handledCount++;
            },
        );

        $guard = new AttributeGuard();
        $queue = new SyncQueue([$handler], $guard);

        $job = new #[UniqueJob(lockSeconds: 3600)] class extends Job {
            public function handle(): void {}
        };

        $queue->dispatch($job);
        $queue->dispatch($job);
        $queue->dispatch($job);

        // The built-in JobHandler is also in the chain; only it counts here
        // because the custom handler's supports() always returns true as well.
        // The key assertion: handle() on the custom handler is called exactly
        // once (first dispatch), and zero more times for the two skipped ones.
        $this->assertSame(1, $handledCount);
    }

    #[Test]
    public function rateLimitedJobIsHandledOnlyUpToMaxAttempts(): void
    {
        $handledCount = 0;

        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(
            function () use (&$handledCount): void {
                $handledCount++;
            },
        );

        $guard = new AttributeGuard();
        $queue = new SyncQueue([$handler], $guard);

        $job = new #[RateLimited(maxAttempts: 2, decaySeconds: 60)] class extends Job {
            public function handle(): void {}
        };

        $queue->dispatch($job); // allowed (attempt 1)
        $queue->dispatch($job); // allowed (attempt 2)
        $queue->dispatch($job); // blocked
        $queue->dispatch($job); // blocked

        $this->assertSame(2, $handledCount);
    }

    #[Test]
    public function uniqueJobCanBeRedispatchedAfterGuardReset(): void
    {
        $handledCount = 0;

        $handler = $this->createStub(HandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(
            function () use (&$handledCount): void {
                $handledCount++;
            },
        );

        $guard = new AttributeGuard();
        $queue = new SyncQueue([$handler], $guard);

        $job = new #[UniqueJob(lockSeconds: 3600, key: 'reset-test')] class extends Job {
            public function handle(): void {}
        };

        $queue->dispatch($job); // allowed
        $queue->dispatch($job); // blocked

        $guard->reset();

        $queue->dispatch($job); // allowed again after reset

        $this->assertSame(2, $handledCount);
    }
}
