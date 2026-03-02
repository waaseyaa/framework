<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Event;

use Waaseyaa\Foundation\Event\BroadcasterInterface;
use Waaseyaa\Foundation\Event\DomainEvent;
use Waaseyaa\Foundation\Event\EventBus;
use Waaseyaa\Foundation\Event\EventStoreInterface;
use Waaseyaa\Foundation\Middleware\EventHandlerInterface;
use Waaseyaa\Foundation\Middleware\EventMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\EventPipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(EventBus::class)]
final class EventBusTest extends TestCase
{
    #[Test]
    public function dispatches_to_sync_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $received = null;
        $dispatcher->addListener(TestNodeSaved::class, function (TestNodeSaved $e) use (&$received) {
            $received = $e;
        });

        $bus = new EventBus(
            syncDispatcher: $dispatcher,
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
        );

        $event = new TestNodeSaved('node', '42', ['title']);
        $bus->dispatch($event);

        $this->assertSame($event, $received);
    }

    #[Test]
    public function dispatches_to_async_bus(): void
    {
        $dispatched = [];
        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($message) use (&$dispatched) {
                $dispatched[] = $message;
                return new Envelope($message);
            });

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $asyncBus,
            broadcaster: $this->createNullBroadcaster(),
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

        $this->assertCount(1, $dispatched);
    }

    #[Test]
    public function dispatches_to_broadcaster(): void
    {
        $broadcast = [];
        $broadcaster = $this->createMock(BroadcasterInterface::class);
        $broadcaster->expects($this->once())
            ->method('broadcast')
            ->willReturnCallback(function (DomainEvent $e) use (&$broadcast) {
                $broadcast[] = $e;
            });

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $broadcaster,
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

        $this->assertCount(1, $broadcast);
    }

    #[Test]
    public function appends_to_event_store_when_available(): void
    {
        $stored = [];
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->once())
            ->method('append')
            ->willReturnCallback(function (DomainEvent $e) use (&$stored) {
                $stored[] = $e;
            });

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
            eventStore: $store,
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

        $this->assertCount(1, $stored);
    }

    #[Test]
    public function works_without_event_store(): void
    {
        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
            eventStore: null,
        );

        // Should not throw
        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));
        $this->assertTrue(true);
    }

    private function createNullMessageBus(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));
        return $bus;
    }

    private function createNullBroadcaster(): BroadcasterInterface
    {
        return $this->createMock(BroadcasterInterface::class);
    }

    #[Test]
    public function dispatches_through_event_pipeline_when_provided(): void
    {
        $pipelineUsed = false;

        $mw = new class($pipelineUsed) implements EventMiddlewareInterface {
            public function __construct(private bool &$used) {}
            public function process(DomainEvent $event, EventHandlerInterface $next): void
            {
                $this->used = true;
                $next->handle($event);
            }
        };

        $pipeline = new EventPipeline([$mw]);

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
            eventPipeline: $pipeline,
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));
        $this->assertTrue($pipelineUsed);
    }

    #[Test]
    public function works_without_event_pipeline(): void
    {
        $received = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TestNodeSaved::class, function (TestNodeSaved $e) use (&$received) {
            $received = $e;
        });

        $bus = new EventBus(
            syncDispatcher: $dispatcher,
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
            eventPipeline: null,
        );

        $event = new TestNodeSaved('node', '42', ['title']);
        $bus->dispatch($event);
        $this->assertSame($event, $received);
    }
}

final class TestNodeSaved extends DomainEvent
{
    public function __construct(
        string $aggregateType,
        string $aggregateId,
        public readonly array $changedFields,
    ) {
        parent::__construct($aggregateType, $aggregateId);
    }

    public function getPayload(): array
    {
        return ['changed_fields' => $this->changedFields];
    }
}
