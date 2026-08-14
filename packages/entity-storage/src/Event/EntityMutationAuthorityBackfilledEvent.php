<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

/** Audit fact emitted after privileged legacy-row authority creation commits. @api */
final readonly class EntityMutationAuthorityBackfilledEvent
{
    public function __construct(
        public string $tenantId,
        public string $entityTypeId,
        public string $entityId,
        public string $reason,
    ) {}
}
