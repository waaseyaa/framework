<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;

final class SpyEntityEventFactory implements EntityEventFactoryInterface
{
    public int $callCount = 0;

    public function create(EntityInterface $entity, ?EntityInterface $originalEntity = null): EntityEvent
    {
        $this->callCount++;

        return new EntityEvent($entity, $originalEntity);
    }
}
