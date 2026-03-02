<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;

interface EventMiddlewareInterface
{
    public function process(DomainEvent $event, EventHandlerInterface $next): void;
}
