<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HttpPipeline
{
    /** @param HttpMiddlewareInterface[] $middleware */
    public function __construct(
        private readonly array $middleware = [],
    ) {}

    public function withMiddleware(HttpMiddlewareInterface $middleware): self
    {
        return new self([...$this->middleware, $middleware]);
    }

    public function handle(Request $request, HttpHandlerInterface $finalHandler): Response
    {
        if ($this->middleware === []) {
            return $finalHandler->handle($request);
        }

        $handler = $finalHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class ($mw, $next) implements HttpHandlerInterface {
                public function __construct(
                    private readonly HttpMiddlewareInterface $middleware,
                    private readonly HttpHandlerInterface $next,
                ) {}

                public function handle(Request $request): Response
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }
}
