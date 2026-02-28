<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

use Aurora\Foundation\Event\DomainEvent;

final class EntityDeleted extends DomainEvent
{
    public function __construct(
        string $entityTypeId,
        string $entityId,
        ?string $tenantId = null,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateType: $entityTypeId,
            aggregateId: $entityId,
            tenantId: $tenantId,
            actorId: $actorId,
        );
    }

    public function getPayload(): array
    {
        return [
            'entity_type' => $this->aggregateType,
            'entity_id' => $this->aggregateId,
        ];
    }
}
