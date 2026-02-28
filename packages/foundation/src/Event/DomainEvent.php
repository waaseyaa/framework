<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\Event;

abstract class DomainEvent extends Event
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly ?string $tenantId = null,
        public readonly ?string $actorId = null,
    ) {
        $this->eventId = Uuid::v7()->toString();
        $this->occurredAt = new \DateTimeImmutable();
    }

    /**
     * Domain-specific payload for serialization and logging.
     */
    abstract public function getPayload(): array;
}
