<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/** Closed reader for the exact authorship input used by preview authorization. @internal */
final class EditorialPreviewSubjectReader
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

    public function read(EntityInterface $entity): EditorialPreviewSubject
    {
        $uid = $entity instanceof EntityBase ? ($this->values)($entity)['uid'] ?? null : $entity->get('uid');

        return new EditorialPreviewSubject(is_int($uid) || is_string($uid) ? $uid : null);
    }
}
