<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase5;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\InMemoryQueue;
use Waaseyaa\Queue\Message\EntityMessage;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\State\MemoryState;
use Waaseyaa\State\StateInterface;

/**
 * Integration tests for waaseyaa/state + waaseyaa/queue.
 *
 * Verifies that MemoryState can track queue processing state,
 * that messages flow through InMemoryQueue and SyncQueue correctly,
 * and that state reflects all processed messages.
 */
#[CoversNothing]
final class StateQueueIntegrationTest extends TestCase
{
    private MemoryState $state;

    protected function setUp(): void
    {
        $this->state = new MemoryState();
    }

    // ---- InMemoryQueue tests ----

    public function testInMemoryQueueCollectsMessages(): void
    {
        $queue = new InMemoryQueue();

        $msg1 = new GenericMessage('email', ['to' => 'user@example.com']);
        $msg2 = new GenericMessage('email', ['to' => 'admin@example.com']);
        $msg3 = new EntityMessage('user', 1, 'created');

        $queue->dispatch($msg1);
        $queue->dispatch($msg2);
        $queue->dispatch($msg3);

        $messages = $queue->getMessages();
        $this->assertCount(3, $messages);
        $this->assertSame($msg1, $messages[0]);
        $this->assertSame($msg2, $messages[1]);
        $this->assertSame($msg3, $messages[2]);
    }

    public function testInMemoryQueueClear(): void
    {
        $queue = new InMemoryQueue();
        $queue->dispatch(new GenericMessage('test', []));
        $queue->dispatch(new GenericMessage('test', []));

        $this->assertCount(2, $queue->getMessages());

        $queue->clear();
        $this->assertCount(0, $queue->getMessages());
    }

    // ---- SyncQueue + Handler tests ----

    public function testSyncQueueDispatchesImmediatelyToHandler(): void
    {
        $processed = [];

        $handler = new class ($processed) implements HandlerInterface {
            /** @param GenericMessage[] $processed */
            public function __construct(private array &$processed) {}

            public function handle(object $message): void
            {
                $this->processed[] = $message;
            }

            public function supports(object $message): bool
            {
                return $message instanceof GenericMessage;
            }
        };

        $queue = new SyncQueue([$handler]);

        $msg1 = new GenericMessage('task', ['action' => 'process']);
        $msg2 = new GenericMessage('task', ['action' => 'clean']);

        $queue->dispatch($msg1);
        $queue->dispatch($msg2);

        $this->assertCount(2, $processed);
        $this->assertSame($msg1, $processed[0]);
        $this->assertSame($msg2, $processed[1]);
    }

    public function testSyncQueueOnlyCallsHandlersThatSupportMessage(): void
    {
        $genericProcessed = [];
        $entityProcessed = [];

        $genericHandler = new class ($genericProcessed) implements HandlerInterface {
            public function __construct(private array &$processed) {}

            public function handle(object $message): void
            {
                $this->processed[] = $message;
            }

            public function supports(object $message): bool
            {
                return $message instanceof GenericMessage;
            }
        };

        $entityHandler = new class ($entityProcessed) implements HandlerInterface {
            public function __construct(private array &$processed) {}

            public function handle(object $message): void
            {
                $this->processed[] = $message;
            }

            public function supports(object $message): bool
            {
                return $message instanceof EntityMessage;
            }
        };

        $queue = new SyncQueue([$genericHandler, $entityHandler]);

        $queue->dispatch(new GenericMessage('ping', []));
        $queue->dispatch(new EntityMessage('node', 1, 'update'));
        $queue->dispatch(new GenericMessage('pong', []));

        $this->assertCount(2, $genericProcessed, 'Generic handler should handle 2 GenericMessages.');
        $this->assertCount(1, $entityProcessed, 'Entity handler should handle 1 EntityMessage.');
    }

    // ---- State + Queue integration ----

    public function testStateTracksQueueProcessingProgress(): void
    {
        $state = $this->state;

        // Initialize state.
        $state->set('queue.processed_count', 0);
        $state->set('queue.last_processed_type', null);

        $handler = new StateTrackingHandler($state);
        $queue = new SyncQueue([$handler]);

        // Dispatch messages.
        $queue->dispatch(new GenericMessage('email_send', ['to' => 'a@b.com']));
        $queue->dispatch(new GenericMessage('cache_clear', ['tag' => 'nodes']));
        $queue->dispatch(new GenericMessage('index_update', ['id' => 42]));

        // Verify state after processing.
        $this->assertSame(3, $state->get('queue.processed_count'));
        $this->assertSame('index_update', $state->get('queue.last_processed_type'));
    }

    public function testStateTracksIndividualMessageResults(): void
    {
        $state = $this->state;
        $handler = new StateTrackingHandler($state);
        $queue = new SyncQueue([$handler]);

        $queue->dispatch(new GenericMessage('task_a', ['value' => 10]));
        $queue->dispatch(new GenericMessage('task_b', ['value' => 20]));
        $queue->dispatch(new GenericMessage('task_c', ['value' => 30]));

        // Each message type should have its own state entry.
        $this->assertSame('completed', $state->get('message.task_a.status'));
        $this->assertSame('completed', $state->get('message.task_b.status'));
        $this->assertSame('completed', $state->get('message.task_c.status'));
    }

    public function testStateMultipleGetReturnsAllTrackedKeys(): void
    {
        $state = $this->state;
        $handler = new StateTrackingHandler($state);
        $queue = new SyncQueue([$handler]);

        $queue->dispatch(new GenericMessage('job_1', []));
        $queue->dispatch(new GenericMessage('job_2', []));

        $values = $state->getMultiple([
            'message.job_1.status',
            'message.job_2.status',
            'queue.processed_count',
        ]);

        $this->assertSame('completed', $values['message.job_1.status']);
        $this->assertSame('completed', $values['message.job_2.status']);
        $this->assertSame(2, $values['queue.processed_count']);
    }

    public function testStateDeleteClearsProcessingState(): void
    {
        $state = $this->state;
        $handler = new StateTrackingHandler($state);
        $queue = new SyncQueue([$handler]);

        $queue->dispatch(new GenericMessage('cleanup_target', []));

        $this->assertSame('completed', $state->get('message.cleanup_target.status'));

        $state->delete('message.cleanup_target.status');
        $this->assertNull($state->get('message.cleanup_target.status'));
    }

    public function testStateBulkSetAndDelete(): void
    {
        $state = $this->state;

        $state->setMultiple([
            'queue.worker.status' => 'running',
            'queue.worker.pid' => 12345,
            'queue.worker.started_at' => '2026-01-01T00:00:00Z',
        ]);

        $this->assertSame('running', $state->get('queue.worker.status'));
        $this->assertSame(12345, $state->get('queue.worker.pid'));
        $this->assertSame('2026-01-01T00:00:00Z', $state->get('queue.worker.started_at'));

        $state->deleteMultiple(['queue.worker.status', 'queue.worker.pid']);
        $this->assertNull($state->get('queue.worker.status'));
        $this->assertNull($state->get('queue.worker.pid'));
        $this->assertSame('2026-01-01T00:00:00Z', $state->get('queue.worker.started_at'));
    }

    // ---- Entity message + state tracking ----

    public function testEntityMessageProcessingUpdatesState(): void
    {
        $state = $this->state;
        $handler = new EntityMessageStateHandler($state);
        $queue = new SyncQueue([$handler]);

        $queue->dispatch(new EntityMessage('node', 1, 'created'));
        $queue->dispatch(new EntityMessage('node', 2, 'updated'));
        $queue->dispatch(new EntityMessage('user', 5, 'deleted'));

        $this->assertSame('created', $state->get('entity.node.1.last_operation'));
        $this->assertSame('updated', $state->get('entity.node.2.last_operation'));
        $this->assertSame('deleted', $state->get('entity.user.5.last_operation'));
        $this->assertSame(3, $state->get('entity.operations_count'));
    }

    // ---- Default values ----

    public function testStateReturnsDefaultWhenKeyNotSet(): void
    {
        $this->assertNull($this->state->get('nonexistent'));
        $this->assertSame('default_value', $this->state->get('nonexistent', 'default_value'));
    }
}

// ---- Supporting handler classes ----

/**
 * Handler that tracks processing state via MemoryState.
 */
class StateTrackingHandler implements HandlerInterface
{
    public function __construct(private readonly StateInterface $state) {}

    public function handle(object $message): void
    {
        if (!$message instanceof GenericMessage) {
            return;
        }

        // Increment processed count.
        $count = $this->state->get('queue.processed_count', 0);
        $this->state->set('queue.processed_count', $count + 1);

        // Track the last processed message type.
        $this->state->set('queue.last_processed_type', $message->type);

        // Track individual message status.
        $this->state->set("message.{$message->type}.status", 'completed');
    }

    public function supports(object $message): bool
    {
        return $message instanceof GenericMessage;
    }
}

/**
 * Handler that tracks entity operation state.
 */
class EntityMessageStateHandler implements HandlerInterface
{
    public function __construct(private readonly StateInterface $state) {}

    public function handle(object $message): void
    {
        if (!$message instanceof EntityMessage) {
            return;
        }

        $key = "entity.{$message->entityTypeId}.{$message->entityId}.last_operation";
        $this->state->set($key, $message->operation);

        $count = $this->state->get('entity.operations_count', 0);
        $this->state->set('entity.operations_count', $count + 1);
    }

    public function supports(object $message): bool
    {
        return $message instanceof EntityMessage;
    }
}
