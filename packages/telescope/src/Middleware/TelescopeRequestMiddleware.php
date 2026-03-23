<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Telescope\TelescopeServiceProvider;

/**
 * Records HTTP request timing and metadata via Telescope's RequestRecorder.
 *
 * Sits at the outermost layer of the middleware pipeline (priority 100) so it
 * captures total request duration including all inner middleware processing.
 */
#[AsMiddleware(pipeline: 'http', priority: 100)]
final class TelescopeRequestMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly TelescopeServiceProvider $telescope,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $startTime = hrtime(true);

        $response = $next->handle($request);

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->recordRequest(
            method: $request->getMethod(),
            uri: $request->getPathInfo(),
            statusCode: $response->getStatusCode(),
            durationMs: $durationMs,
            controller: $this->resolveController($request),
        );

        return $response;
    }

    public function recordRequest(
        string $method,
        string $uri,
        int $statusCode,
        float $durationMs,
        string $controller = '',
    ): void {
        $recorder = $this->telescope->getRequestRecorder();

        if ($recorder === null) {
            return;
        }

        $recorder->record(
            method: $method,
            uri: $uri,
            statusCode: $statusCode,
            duration: $durationMs,
            controller: $controller,
        );
    }

    private function resolveController(Request $request): string
    {
        $controller = $request->attributes->get('_controller', '');

        return is_string($controller) ? $controller : '';
    }
}
