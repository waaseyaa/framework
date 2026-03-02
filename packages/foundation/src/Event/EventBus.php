<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Event;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Foundation\Middleware\EventHandlerInterface;
use Waaseyaa\Foundation\Middleware\EventPipeline;

final class EventBus
{
    public function __construct(
        private readonly EventDispatcherInterface $syncDispatcher,
        private readonly MessageBusInterface $asyncBus,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?EventStoreInterface $eventStore = null,
        private readonly ?EventPipeline $eventPipeline = null,
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        $this->eventStore?->append($event);

        $syncHandler = new class($this->syncDispatcher) implements EventHandlerInterface {
            public function __construct(
                private readonly EventDispatcherInterface $dispatcher,
            ) {}

            public function handle(DomainEvent $event): void
            {
                $this->dispatcher->dispatch($event);
            }
        };

        if ($this->eventPipeline !== null) {
            $this->eventPipeline->handle($event, $syncHandler);
        } else {
            $syncHandler->handle($event);
        }

        $this->asyncBus->dispatch($event);
        $this->broadcaster->broadcast($event);
    }
}
