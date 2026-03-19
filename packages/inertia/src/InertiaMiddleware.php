<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

#[AsMiddleware(pipeline: 'http', priority: 20)]
final class InertiaMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly string $version,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        if ($request->headers->get('X-Inertia') !== 'true') {
            return $next->handle($request);
        }

        $clientVersion = $request->headers->get('X-Inertia-Version');
        if ($clientVersion !== null && $clientVersion !== $this->version) {
            return new Response('', 409, [
                'X-Inertia-Location' => $request->getRequestUri(),
            ]);
        }

        return $next->handle($request);
    }
}
