<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Event;

use Waaseyaa\Entity\EntityInterface;

interface EntityEventFactoryInterface
{
    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent;
}
