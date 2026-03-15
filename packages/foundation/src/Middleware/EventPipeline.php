<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;

final class EventPipeline
{
    /** @param EventMiddlewareInterface[] $middleware */
    public function __construct(
        private readonly array $middleware = [],
    ) {}

    public function withMiddleware(EventMiddlewareInterface $middleware): self
    {
        return new self([...$this->middleware, $middleware]);
    }

    public function handle(DomainEvent $event, EventHandlerInterface $finalHandler): void
    {
        if ($this->middleware === []) {
            $finalHandler->handle($event);
            return;
        }

        $handler = $finalHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class ($mw, $next) implements EventHandlerInterface {
                public function __construct(
                    private readonly EventMiddlewareInterface $middleware,
                    private readonly EventHandlerInterface $next,
                ) {}

                public function handle(DomainEvent $event): void
                {
                    $this->middleware->process($event, $this->next);
                }
            };
        }

        $handler->handle($event);
    }
}
