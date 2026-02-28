<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

interface EventStoreInterface
{
    public function append(DomainEvent $event): void;
}
