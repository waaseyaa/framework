<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\EntityBase;

/** Closed authority over the four Protected relationship endpoint selectors. @api */
final class RelationshipTopologyReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $valueAuthority;

    public function __construct()
    {
        $authority = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
        $this->valueAuthority = $authority;
    }

    public function read(Relationship $relationship): RelationshipTopology
    {
        $values = ($this->valueAuthority)($relationship);

        return new RelationshipTopology(
            (string) ($values['from_entity_type'] ?? ''),
            (string) ($values['from_entity_id'] ?? ''),
            (string) ($values['to_entity_type'] ?? ''),
            (string) ($values['to_entity_id'] ?? ''),
        );
    }
}
