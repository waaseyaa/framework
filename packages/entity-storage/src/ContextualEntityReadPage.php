<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Entity\EntityInterface;

/** Materialized authorized entities and survivor count from one snapshot. */
final readonly class ContextualEntityReadPage
{
    /** @param list<EntityInterface> $entities */
    public function __construct(
        public array $entities,
        public int $total,
    ) {}
}
