<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;

interface EventHandlerInterface
{
    public function handle(DomainEvent $event): void;
}
