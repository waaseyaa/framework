<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/** Closed reader for the two identifiers persisted by the entity-write audit. @internal */
final class EntityWriteAuditSubjectReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $values;

    public function __construct()
    {
        $this->values = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(EntityInterface $entity): EntityWriteAuditSubject
    {
        $values = $entity instanceof EntityBase ? ($this->values)($entity) : [];
        $uid = $values['uid'] ?? null;
        $tenantId = $values['tenant_id'] ?? null;

        return new EntityWriteAuditSubject(
            authorId: is_int($uid) || is_string($uid) ? $uid : null,
            tenantId: is_string($tenantId) && $tenantId !== '' ? $tenantId : null,
        );
    }
}
