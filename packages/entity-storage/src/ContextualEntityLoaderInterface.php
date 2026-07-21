<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Entity\EntityInterface;

/** Snapshot-bound entity hydrator for contextual authorization. @internal */
interface ContextualEntityLoaderInterface
{
    public function authorizationBoundary(): object;

    /**
     * @param list<int|string> $ids
     * @return array<int|string, EntityInterface>
     */
    public function loadMultiple(array $ids): array;
}
