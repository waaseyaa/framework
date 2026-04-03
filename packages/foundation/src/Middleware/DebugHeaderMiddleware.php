<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

/**
 * Adds X-Debug-* headers to all responses when APP_DEBUG=true.
 *
 * Gives SPA devtools and curl users useful debugging signals without
 * requiring an HTML toolbar.
 *
 * Headers:
 *   X-Debug-Time       — request duration in milliseconds
 *   X-Debug-Memory     — peak memory usage (e.g. "4.2MB")
 *   X-Debug-Request-Id — links log entries to this request
 *
 * X-Debug-Queries is deferred until a query counter is added to the
 * database layer (see debugging-dx spec).
 */
#[AsMiddleware(pipeline: 'http', priority: 90)]
final class DebugHeaderMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly float $startTime,
        private readonly ?string $requestId = null,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        $elapsedMs = (int) round((microtime(true) - $this->startTime) * 1000);
        $peakMb = round(memory_get_peak_usage(true) / 1_048_576, 1);

        $response->headers->set('X-Debug-Time', $elapsedMs . 'ms');
        $response->headers->set('X-Debug-Memory', $peakMb . 'MB');

        if ($this->requestId !== null) {
            $response->headers->set('X-Debug-Request-Id', $this->requestId);
        }

        return $response;
    }
}
