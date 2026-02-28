<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

interface BroadcasterInterface
{
    public function broadcast(DomainEvent $event): void;
}
