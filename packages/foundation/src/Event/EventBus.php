<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EventBus
{
    public function __construct(
        private readonly EventDispatcherInterface $syncDispatcher,
        private readonly MessageBusInterface $asyncBus,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?EventStoreInterface $eventStore = null,
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        $this->eventStore?->append($event);
        $this->syncDispatcher->dispatch($event);
        $this->asyncBus->dispatch($event);
        $this->broadcaster->broadcast($event);
    }
}
