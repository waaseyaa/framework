<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Policy;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValues;

/** Closed publication-state authority for the legacy account-policy adapter. @internal */
final class PublishedContentStatusReader
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

    public function isPublished(EntityInterface $entity): bool
    {
        $status = $entity instanceof EntityBase
            ? ($this->values)($entity)['status'] ?? null
            : $entity->get('status');

        return EntityValues::statusToInt($status) === 1;
    }
}
