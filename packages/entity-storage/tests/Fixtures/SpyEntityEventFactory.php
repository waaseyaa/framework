<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;

final class SpyEntityEventFactory implements EntityEventFactoryInterface
{
    public int $callCount = 0;

    /** @var list<array{entity: EntityInterface, originalEntity: ?EntityInterface}> */
    public array $calls = [];

    public function create(EntityInterface $entity, ?EntityInterface $originalEntity = null): EntityEvent
    {
        $this->callCount++;
        $this->calls[] = ['entity' => $entity, 'originalEntity' => $originalEntity];

        return new EntityEvent($entity, $originalEntity);
    }
}
