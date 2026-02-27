<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

use Aurora\Entity\EntityInterface;
use Symfony\Contracts\EventDispatcher\Event;

class EntityEvent extends Event
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly ?EntityInterface $originalEntity = null,
    ) {}
}
