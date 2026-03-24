<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Event;

use Waaseyaa\Entity\EntityInterface;

final class DefaultEntityEventFactory implements EntityEventFactoryInterface
{
    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent {
        return new EntityEvent($entity, $originalEntity);
    }
}
